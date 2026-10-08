<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;

/**
 * Sends one post to the hosted index, or withdraws it.
 *
 * 🚨 Only ever public content. The hosted index answers whoever asks, so
 * anything in it is effectively readable by every member — a post a guest
 * cannot read must never be indexed, because hosted retrieval has no concept
 * of who is asking and would happily quote it back.
 */
class IndexPost implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private int $postId,
        private bool $withdraw = false,
    ) {
    }

    public function handle(RelayClient $relay, LoggerInterface $log): void
    {
        if (! $relay->configured()) {
            return;
        }

        if ($this->withdraw) {
            $this->send($relay, $log, ['postId' => $this->postId, 'deleted' => true]);

            return;
        }

        /*
         * 🚨 Public means "a signed-out visitor can read it", decided by the
         * forum's own visibility rules: tag permissions, private and hidden
         * discussions, hidden posts and posts awaiting approval all included.
         * Checking a couple of columns by hand missed staff-only tags and
         * unapproved posts, and the assistant then answered members from them.
         */
        /** @var CommentPost|null $post */
        $post = CommentPost::query()
            ->whereVisibleTo(new Guest())
            ->with('discussion')
            ->find($this->postId);

        $discussion = $post?->discussion;

        /*
         * 🚨 Withdraw rather than skip. A post that becomes non-public — hidden,
         * moved into a private discussion or a restricted tag — must be REMOVED
         * from the index, not merely left out of future writes. Skipping would
         * leave the old copy retrievable forever.
         */
        if (! $post || ! $discussion || $post->hidden_at || $discussion->hidden_at || $discussion->is_private) {
            $this->send($relay, $log, ['postId' => $this->postId, 'deleted' => true]);

            return;
        }

        $this->send($relay, $log, [
            'postId' => (int) $post->id,
            'title' => (string) $discussion->title,
            'body' => strip_tags((string) $post->content),
            'url' => '/d/'.$discussion->id.'-'.$discussion->slug.'/'.$post->number,
        ]);
    }

    private function send(RelayClient $relay, LoggerInterface $log, array $payload): void
    {
        try {
            $relay->post('v1/index', $payload);
        } catch (RelayException $e) {
            /*
             * A site on the local tier gets a refusal here, which is correct and
             * not worth logging as a problem — it keeps its own index and never
             * sends us anything. Everything else is worth a line.
             */
            if (! str_contains($e->getMessage(), 'refused')) {
                $log->info('[steward] could not index a post', [
                    'post' => $payload['postId'], 'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}

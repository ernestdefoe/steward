<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Post\CommentPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;

/**
 * Sends one post to the hosted index, or withdraws it.
 *
 * 🚨 Only ever public content. The assistant answers whoever asks, so anything
 * in the index is effectively readable by every member — a post in a private
 * or restricted discussion must never be indexed, because retrieval has no
 * concept of who is asking and would happily quote it back.
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

        try {
            /** @var CommentPost $post */
            $post = CommentPost::query()->with('discussion')->findOrFail($this->postId);
        } catch (ModelNotFoundException) {
            return;
        }

        $discussion = $post->discussion;

        if (! $discussion) {
            return;
        }

        /*
         * 🚨 Withdraw rather than skip. A post that becomes non-public — hidden,
         * or moved into a private discussion — must be REMOVED from the index,
         * not merely left out of future writes. Skipping would leave the old
         * copy retrievable forever.
         */
        if ($post->hidden_at || $discussion->hidden_at || $discussion->is_private) {
            $this->send($relay, $log, ['postId' => $this->postId, 'deleted' => true]);

            return;
        }

        $this->send($relay, $log, [
            'postId' => (int) $post->id,
            'title'  => (string) $discussion->title,
            'body'   => strip_tags((string) $post->content),
            'url'    => '/d/' . $discussion->id . '-' . $discussion->slug . '/' . $post->number,
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

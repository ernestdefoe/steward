<?php

namespace Ernestdefoe\Steward\Index;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Psr\Log\LoggerInterface;

/**
 * Sends posts to the hosted index, or withdraws them.
 *
 * 🚨 Only ever public content. The hosted index answers whoever asks, so
 * anything in it is effectively readable by every member — a post a guest
 * cannot read must never be indexed, because hosted retrieval has no concept
 * of who is asking and would happily quote it back.
 */
class Indexer
{
    public function __construct(
        private RelayClient $relay,
        private LoggerInterface $log,
        private UrlGenerator $url,
        private SlugManager $slugs,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Index each post a guest can read and withdraw every other one.
     *
     * 🚨 Public means "a signed-out visitor can read it", decided by the
     * forum's own visibility rules: tag permissions, private and hidden
     * discussions, hidden posts and posts awaiting approval all included.
     * One query for the whole batch, with the discussions loaded alongside.
     *
     * @param list<int> $postIds
     */
    public function sync(array $postIds): void
    {
        if (! $this->relay->configured() || $postIds === []) {
            return;
        }

        $public = CommentPost::query()
            ->whereVisibleTo(new Guest())
            // CommentPost does not narrow by type: a rename would be sent as "Array".
            ->where('posts.type', 'comment')
            ->whereIn('posts.id', $postIds)
            ->with('discussion')
            ->get()
            ->keyBy('id');

        // With answers switched off nothing new is sent, but withdrawals still are.
        $adding = (bool) $this->settings->get('steward.answers');

        foreach ($postIds as $id) {
            $post = $public->get($id);

            /*
             * 🚨 Withdraw rather than skip. A post that stops being public must
             * be REMOVED from the index, not merely left out of future writes:
             * skipping would leave the old copy retrievable forever.
             */
            if (! $post?->discussion) {
                $this->send(['postId' => $id, 'deleted' => true]);
            } elseif ($adding) {
                $this->send($this->document($post));
            }
        }
    }

    /** @return array<string, mixed> */
    private function document(CommentPost $post): array
    {
        $discussion = $post->discussion;

        return [
            'postId' => (int) $post->id,
            'title' => (string) $discussion->title,
            'body' => strip_tags((string) $post->content),
            'url' => $this->url->to('forum')->route('discussion', [
                'id' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
                'near' => $post->number,
            ]),
        ];
    }

    /** @param list<int> $postIds */
    public function withdraw(array $postIds): void
    {
        if (! $this->relay->configured()) {
            return;
        }

        foreach ($postIds as $id) {
            $this->send(['postId' => $id, 'deleted' => true]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws RelayException when the relay is down or unreachable: the rest of
     *                        the batch would only wait out the same timeout, so
     *                        the job stops and is tried again later.
     */
    private function send(array $payload): void
    {
        try {
            $this->relay->post('v1/index', $payload);
        } catch (RelayException $e) {
            if ($e->retryable) {
                throw $e;
            }

            /*
             * A site on the local tier gets a refusal here, which is correct and
             * not worth logging as a problem — it keeps its own index and never
             * sends us anything. Everything else is worth a line.
             */
            if ($e->reason !== 'refused') {
                $this->log->info('[steward] could not index a post', [
                    'post' => $payload['postId'], 'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}

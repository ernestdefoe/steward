<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Job\ScreenPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;

/**
 * Sends new posts to be screened — on the queue, never inline.
 *
 * 🚨 Posting must not wait on us. Screening involves a network call in the
 * worst case, and holding the member's request open while a third party
 * thinks is how an AI feature makes a forum feel slow to everybody, including
 * the 99% whose posts are cleared for free.
 */
class ScreenNewPosts
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
    ) {
    }

    public function handle(Posted $event): void
    {
        if (! (bool) $this->settings->get('steward.moderation')) {
            return;
        }

        $post = $event->post;

        // Only real content. Event posts — renames, tag changes — have nothing
        // to moderate and calling formatContent() on one throws.
        if (! ($post instanceof CommentPost) || ! $post->id) {
            return;
        }

        $this->queue->push(new ScreenPost($post->id));
    }
}

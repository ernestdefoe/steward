<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Job\IndexPost;
use Ernestdefoe\Steward\Job\ScreenPost;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;

/**
 * Keeps moderation and the index in step with what is actually on the forum.
 *
 * 🚨 Everything here goes on the queue, never inline. Posting must not wait on
 * us: screening can involve a network call, and holding a member's request open
 * while a third party thinks is how an AI feature makes a forum feel slow to
 * everybody — including the 99% whose posts are cleared for free.
 */
class ScreenNewPosts
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
    ) {
    }

    /**
     * 🚨 A subscriber, not four listeners.
     *
     * Extend\Event::listen() takes callable|string — an array of
     * [Class::class, 'method'] is neither, and passing one is a BOOT error that
     * takes the whole forum down with a 500, not a quiet misconfiguration.
     * Binding several methods from one class is what subscribe() is for.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, [$this, 'handle']);
        $events->listen(Revised::class, [$this, 'whenRevised']);
        $events->listen(Restored::class, [$this, 'whenRevised']);
        $events->listen(Hidden::class, [$this, 'whenHidden']);
        $events->listen(Deleted::class, [$this, 'whenHidden']);
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;

        // Only real content. Event posts — renames, tag changes — have nothing
        // to moderate and calling formatContent() on one throws.
        if (! ($post instanceof CommentPost) || ! $post->id) {
            return;
        }

        if ((bool) $this->settings->get('steward.moderation')) {
            $this->queue->push(new ScreenPost($post->id));
        }

        if ((bool) $this->settings->get('steward.answers')) {
            $this->queue->push(new IndexPost($post->id));
        }
    }

    /**
     * An edited or restored post is re-sent, replacing what we held.
     *
     * Without this the index only ever grows, and the assistant answers from
     * text the author already removed.
     */
    public function whenRevised(object $event): void
    {
        $post = $event->post ?? null;

        if ($post && $post->id && (bool) $this->settings->get('steward.answers')) {
            $this->queue->push(new IndexPost($post->id));
        }
    }

    /**
     * Withdraw a post from the index when it stops being public.
     *
     * 🚨 Hiding must REMOVE, not merely stop updating. Otherwise a moderator
     * hides something and the assistant carries on quoting it to anyone who
     * asks the right question — gone from the forum, still in the answers.
     */
    public function whenHidden(object $event): void
    {
        $post = $event->post ?? null;

        if ($post && $post->id && (bool) $this->settings->get('steward.answers')) {
            $this->queue->push(new IndexPost($post->id, withdraw: true));
        }
    }
}

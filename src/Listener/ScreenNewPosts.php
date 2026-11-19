<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Index\Requeue;
use Ernestdefoe\Steward\Job\ScreenPost;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
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
 *
 * Tags and Approval have their own listeners, registered only when those
 * extensions are enabled (see extend.php).
 */
class ScreenNewPosts
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
        private Requeue $requeue,
    ) {
    }

    /**
     * 🚨 A subscriber, not several listeners.
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
        $events->listen('eloquent.saved: '.Discussion::class, [$this, 'whenDiscussionSaved']);
        $events->listen(DiscussionDeleting::class, [$this, 'whenDiscussionDeleting']);
    }

    /** Hidden, restored, made private or public, approved: anything that moves who can read it. */
    public function whenDiscussionSaved(Discussion $discussion): void
    {
        if ($discussion->wasChanged(['hidden_at', 'is_private', 'is_approved'])) {
            $this->requeue->discussion($discussion);
        }
    }

    public function whenDiscussionDeleting(DiscussionDeleting $event): void
    {
        $this->requeue->discussion($event->discussion, withdraw: true);
    }

    public function handle(Posted $event): void
    {
        // Posted only ever carries a comment (event posts — renames, tag
        // changes — never fire it), so the one thing to check is that it saved.
        if (! $event->post->id) {
            return;
        }

        if ((bool) $this->settings->get('steward.moderation')) {
            $this->queue->push(new ScreenPost($event->post->id));
        }

        $this->requeue->post($event->post->id);
    }

    /**
     * An edited or restored post is re-sent, replacing what we held.
     *
     * Without this the index only ever grows, and the assistant answers from
     * text the author already removed.
     */
    public function whenRevised(Revised|Restored $event): void
    {
        $this->requeue->post((int) $event->post->id);
    }

    /**
     * 🚨 Hiding must REMOVE, not merely stop updating. Otherwise a moderator
     * hides something and the assistant carries on quoting it to anyone who
     * asks the right question — gone from the forum, still in the answers.
     */
    public function whenHidden(Hidden|Deleted $event): void
    {
        $this->requeue->post((int) $event->post->id, withdraw: true);
    }
}

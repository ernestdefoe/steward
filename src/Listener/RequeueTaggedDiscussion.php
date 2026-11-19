<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Index\Requeue;
use Flarum\Tags\Event\DiscussionWasTagged;

/**
 * Re-tagging can move a discussion into or out of a staff-only tag, so its
 * posts are re-checked. Registered only while flarum/tags is enabled.
 */
class RequeueTaggedDiscussion
{
    public function __construct(private Requeue $requeue)
    {
    }

    public function handle(DiscussionWasTagged $event): void
    {
        $this->requeue->discussion($event->discussion);
    }
}

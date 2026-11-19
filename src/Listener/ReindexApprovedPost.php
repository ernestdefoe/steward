<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Index\Requeue;
use Flarum\Approval\Event\PostWasApproved;

/**
 * A post awaiting approval is not public; once approved it is. Registered only
 * while flarum/approval is enabled.
 */
class ReindexApprovedPost
{
    public function __construct(private Requeue $requeue)
    {
    }

    public function handle(PostWasApproved $event): void
    {
        $this->requeue->post((int) $event->post->id);
    }
}

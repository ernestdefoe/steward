<?php

namespace Ernestdefoe\Steward\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The moderation queue is readable only by whoever holds steward.review, and
 * only for posts that person can see — a reviewer is never shown the excerpt,
 * author or link of a post in a tag or discussion closed to them.
 */
class ScopeReviewVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if (! $actor->hasPermission('steward.review')) {
            $query->whereKey([]);

            return;
        }

        $query->whereHas('post', fn (Builder $posts) => $posts->whereVisibleTo($actor));
    }
}

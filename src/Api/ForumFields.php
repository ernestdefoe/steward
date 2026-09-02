<?php

namespace Ernestdefoe\Steward\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/**
 * Tells the frontend whether this person may open the queue, so the nav item
 * is only offered to someone it will actually work for.
 */
class ForumFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canReviewSteward')
                ->get(fn ($model, Context $context) => $context->getActor()->hasPermission('steward.review')),

            // So the nav item is only offered where asking actually works.
            Schema\Boolean::make('stewardAnswers')
                ->get(fn () => (bool) resolve(\Flarum\Settings\SettingsRepositoryInterface::class)
                    ->get('steward.answers')),
        ];
    }
}

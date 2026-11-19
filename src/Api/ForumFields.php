<?php

namespace Ernestdefoe\Steward\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Tells the frontend whether this person may open the queue, so the nav item
 * is only offered to someone it will actually work for.
 */
class ForumFields
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canReviewSteward')
                ->get(fn ($model, Context $context) => $context->getActor()->hasPermission('steward.review')),

            // So the nav item is only offered where asking actually works.
            Schema\Boolean::make('stewardAnswers')
                ->get(fn () => (bool) $this->settings->get('steward.answers')),
        ];
    }
}

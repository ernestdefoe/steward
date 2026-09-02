<?php

use Ernestdefoe\Steward\Api\ListReviewsController;
use Ernestdefoe\Steward\Api\ResolveReviewController;
use Ernestdefoe\Steward\Listener\ScreenNewPosts;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/moderation/queue', 'steward.queue')
        ->route('/ask', 'steward.ask'),

    (new Extend\ApiResource(\Flarum\Api\Resource\ForumResource::class))
        ->fields(\Ernestdefoe\Steward\Api\ForumFields::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * 🚨 Editing re-indexes and hiding withdraws. An index that only ever grows
     * will answer from text the author removed and from posts a moderator hid —
     * both invisible on the forum, both still quotable.
     */
    (new Extend\Event())
        ->subscribe(ScreenNewPosts::class),

    (new Extend\Routes('api'))
        ->get('/steward/reviews', 'steward.reviews', ListReviewsController::class)
        ->post('/steward/reviews/{id}/resolve', 'steward.reviews.resolve', ResolveReviewController::class)
        ->get('/steward/usage', 'steward.usage', \Ernestdefoe\Steward\Api\UsageController::class)
        ->post('/steward/ask', 'steward.ask', \Ernestdefoe\Steward\Api\AskController::class),

    (new Extend\ServiceProvider())
        ->register(\Ernestdefoe\Steward\StewardServiceProvider::class),
];

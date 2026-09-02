<?php

use Ernestdefoe\Steward\Api\ListReviewsController;
use Ernestdefoe\Steward\Api\ResolveReviewController;
use Ernestdefoe\Steward\Listener\ScreenNewPosts;
use Flarum\Extend;
use Flarum\Post\Event\Posted;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/moderation/queue', 'steward.queue'),

    (new Extend\ApiResource(\Flarum\Api\Resource\ForumResource::class))
        ->fields(\Ernestdefoe\Steward\Api\ForumFields::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Event())
        ->listen(Posted::class, ScreenNewPosts::class),

    (new Extend\Routes('api'))
        ->get('/steward/reviews', 'steward.reviews', ListReviewsController::class)
        ->post('/steward/reviews/{id}/resolve', 'steward.reviews.resolve', ResolveReviewController::class),

    (new Extend\ServiceProvider())
        ->register(\Ernestdefoe\Steward\StewardServiceProvider::class),
];

<?php

use Ernestdefoe\Steward\Listener\ScreenNewPosts;
use Flarum\Extend;
use Flarum\Post\Event\Posted;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Event())
        ->listen(Posted::class, ScreenNewPosts::class),

    (new Extend\ServiceProvider())
        ->register(\Ernestdefoe\Steward\StewardServiceProvider::class),
];

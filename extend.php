<?php

use Ernestdefoe\Steward\Access\ScopeReviewVisibility;
use Ernestdefoe\Steward\Api;
use Ernestdefoe\Steward\Console;
use Ernestdefoe\Steward\Listener;
use Ernestdefoe\Steward\Model\Review;
use Ernestdefoe\Steward\StewardServiceProvider;
use Flarum\Api\Resource\ForumResource;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Extend;
use Flarum\Tags\Event\DiscussionWasTagged;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The Ask and queue pages are lazy chunks in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/moderation/queue', 'steward.queue')
        ->route('/ask', 'steward.ask'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * The one place Steward's defaults live. They are read at run time, not
     * written at install, so correcting one reaches every forum that never
     * changed it. The answer threshold is measured: on the live index genuine
     * questions floored at 0.659 and nonsense still matched at 0.576.
     */
    (new Extend\Settings())
        ->default('steward.relay_url', 'https://ernestdefoe.online')
        ->default('steward.moderation', true)
        ->default('steward.answers', true)
        ->default('steward.answer_threshold', 0.62)
        ->default('steward.trusted_posts', 25)
        ->default('steward.new_account_days', 7),

    new Extend\ApiResource(Api\Resource\ReviewResource::class),
    new Extend\ApiResource(Api\Resource\QuestionResource::class),

    (new Extend\ModelVisibility(Review::class))
        ->scope(ScopeReviewVisibility::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    /*
     * 🚨 Editing re-indexes and hiding withdraws. An index that only ever grows
     * will answer from text the author removed and from posts a moderator hid —
     * both invisible on the forum, both still quotable.
     */
    (new Extend\Event())
        ->subscribe(Listener\ScreenNewPosts::class)
        ->listen(\Flarum\Settings\Event\Saved::class, Listener\ForgetUsage::class)
        ->listen(\Flarum\Settings\Event\Saving::class, Listener\ValidateSettings::class),

    // Re-tagging and approval change who can read a post, so they re-index it.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())->listen(DiscussionWasTagged::class, Listener\RequeueTaggedDiscussion::class),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())->listen(PostWasApproved::class, Listener\ReindexApprovedPost::class),
        ]),

    /*
     * The one plain route, on purpose: usage is not a record but a proxy for
     * the relay, kept server-side because the site key is a bearer credential.
     */
    (new Extend\Routes('api'))
        ->get('/steward/usage', 'steward.usage', Api\UsageController::class),

    // An ask spends relay quota and a queue worker's time; cap it per member.
    (new Extend\ThrottleApi())
        ->set('stewardAsk', Api\AskThrottler::class),

    (new Extend\Console())
        ->command(Console\WithdrawRestrictedCommand::class)
        ->command(Console\IndexCommand::class),

    (new Extend\ServiceProvider())
        ->register(StewardServiceProvider::class),
];

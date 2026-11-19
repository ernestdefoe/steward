<?php

namespace Ernestdefoe\Steward\Job;

use Carbon\Carbon;
use Ernestdefoe\Steward\Model\Review;
use Ernestdefoe\Steward\Moderation\Decision;
use Ernestdefoe\Steward\Moderation\Screener;
use Flarum\Post\CommentPost;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Psr\Log\LoggerInterface;

class ScreenPost extends AbstractJob
{
    public function __construct(public readonly int $postId)
    {
        parent::__construct();
    }

    public function handle(Screener $screener, LoggerInterface $log): void
    {
        // The job carries an id, not a model, so a post deleted between posting
        // and screening is simply not found: nothing to do, not a failed job.
        $post = CommentPost::query()->with('user')->find($this->postId);
        $user = $post?->user;

        if (! $post || ! $user) {
            return;
        }

        $decision = $screener->screen((string) $post->content, self::author($user));

        /*
         * 🚨 An unscreened post is RECORDED, not silently allowed.
         *
         * If the allowance ran out or the relay was down we let the post
         * through, but an admin has to be able to find the window where
         * nothing was checked.
         */
        if ($decision->action === Decision::ALLOW && ! $decision->unscreened) {
            return;
        }

        Review::query()->updateOrCreate(['post_id' => $post->id], [
            'user_id' => $user->id,
            'action' => $decision->action,
            'source' => $decision->source,
            'reasons' => json_encode($decision->reasons),
            'confidence' => $decision->confidence,
            'unscreened' => $decision->unscreened,
            'created_at' => Carbon::now(),
        ]);

        if ($decision->action === Decision::GUARDIAN) {
            // Guardian owns the reporting duty. Steward reports nothing itself:
            // the review is marked "guardian" for a human, and the forum's log
            // gets a warning an admin (or Guardian) can act on.
            $log->warning('[steward] child-safety signal escalated to Guardian', ['post_id' => $post->id]);
        }
    }

    /** @return array{postCount:int, accountAgeDays:int, isModerator:bool} */
    private static function author(User $user): array
    {
        return [
            'postCount' => (int) ($user->comment_count ?? 0),
            'accountAgeDays' => $user->joined_at ? (int) $user->joined_at->diffInDays(Carbon::now()) : 999,
            'isModerator' => $user->isAdmin() || $user->hasPermission('discussion.hide'),
        ];
    }
}

<?php

namespace Ernestdefoe\Steward\Job;

use Carbon\Carbon;
use Ernestdefoe\Steward\Moderation\Decision;
use Ernestdefoe\Steward\Moderation\Screener;
use Ernestdefoe\Steward\Model\Review;
use Flarum\Post\CommentPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;

class ScreenPost implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private int $postId)
    {
    }

    public function handle(Screener $screener, LoggerInterface $log): void
    {
        try {
            /** @var CommentPost $post */
            $post = CommentPost::query()->with('user')->findOrFail($this->postId);
        } catch (ModelNotFoundException) {
            // Deleted between posting and screening. Nothing to do, and not
            // worth a failed job.
            return;
        }

        $user = $post->user;

        if (! $user) {
            return;
        }

        $decision = $screener->screen((string) $post->content, [
            'postCount'      => (int) ($user->comment_count ?? 0),
            'accountAgeDays' => $user->joined_at ? $user->joined_at->diffInDays(Carbon::now()) : 999,
            'isModerator'    => $user->isAdmin() || $user->hasPermission('discussion.hide'),
        ]);

        /*
         * 🚨 An unscreened post is RECORDED, not silently allowed.
         *
         * If the allowance ran out or the relay was down we let the post
         * through — the right call — but an admin has to be able to find the
         * window where nothing was checked. Silence here would mean a forum
         * believing it was moderated during an outage it never knew about.
         */
        if ($decision->action === Decision::ALLOW && ! $decision->unscreened) {
            return;
        }

        Review::query()->updateOrCreate(
            ['post_id' => $post->id],
            [
                'user_id'    => $user->id,
                'action'     => $decision->action,
                'source'     => $decision->source,
                'reasons'    => json_encode($decision->reasons),
                'confidence' => $decision->confidence,
                'unscreened' => $decision->unscreened,
                'created_at' => Carbon::now(),
            ]
        );

        if ($decision->action === Decision::GUARDIAN) {
            // Guardian owns the reporting duty; we only make sure it is told
            // and that the post is queued for a human either way.
            $log->warning('[steward] child-safety signal escalated to Guardian', [
                'post_id' => $post->id,
            ]);
        }
    }
}

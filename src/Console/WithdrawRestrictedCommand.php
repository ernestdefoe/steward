<?php

namespace Ernestdefoe\Steward\Console;

use Ernestdefoe\Steward\Job\IndexPosts;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Illuminate\Contracts\Queue\Queue;

/**
 * php flarum steward:withdraw-restricted.
 *
 * Removes from the hosted index every post a signed-out visitor cannot read.
 *
 * Until this command was added, a post was indexed unless its discussion was private or hidden,
 * so posts in staff-only or restricted tags, and posts awaiting approval, went
 * into the index and could be answered from. New writes are fixed; this clears
 * what was already sent. Run it once after updating. Public posts are left
 * alone, so it costs one call per restricted post, queued in batches, and nothing for the rest.
 */
class WithdrawRestrictedCommand extends AbstractCommand
{
    public function __construct(private Queue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('steward:withdraw-restricted')
            ->setDescription('Withdraw every post a guest cannot read from the Steward answer index.');
    }

    protected function fire(): int
    {
        $guest = new Guest();
        $queued = 0;

        CommentPost::query()->where('type', 'comment')->select('id')->chunkById(IndexPosts::BATCH, function ($chunk) use ($guest, &$queued) {
            $ids = $chunk->pluck('id')->map(fn ($id) => (int) $id)->all();
            $public = CommentPost::query()->whereVisibleTo($guest)->whereIn('posts.id', $ids)->pluck('posts.id')->map(fn ($id) => (int) $id)->all();
            $restricted = array_values(array_diff($ids, $public));

            if ($restricted !== []) {
                $this->queue->push(new IndexPosts($restricted, withdraw: true));
                $queued += count($restricted);
            }
        });

        $this->info("Queued {$queued} withdrawals.");

        return 0;
    }
}

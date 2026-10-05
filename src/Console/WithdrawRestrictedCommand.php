<?php

namespace Ernestdefoe\Steward\Console;

use Ernestdefoe\Steward\Job\IndexPost;
use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Illuminate\Contracts\Queue\Queue;

/**
 * php flarum steward:withdraw-restricted
 *
 * Removes from the hosted index every post a signed-out visitor cannot read.
 *
 * Until this command was added, a post was indexed unless its discussion was private or hidden,
 * so posts in staff-only or restricted tags, and posts awaiting approval, went
 * into the index and could be answered from. New writes are fixed; this clears
 * what was already sent. Run it once after updating. Public posts are left
 * alone, so it costs one call per restricted post and nothing for the rest.
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

        CommentPost::query()->select('id')->chunkById(500, function ($chunk) use ($guest, &$queued) {
            $ids = $chunk->pluck('id')->all();
            $public = CommentPost::query()->whereVisibleTo($guest)->whereIn('posts.id', $ids)->pluck('posts.id')->all();

            foreach (array_diff($ids, $public) as $id) {
                $this->queue->push(new IndexPost((int) $id, withdraw: true));
                $queued++;
            }
        });

        $this->info("Queued {$queued} withdrawals.");

        return 0;
    }
}

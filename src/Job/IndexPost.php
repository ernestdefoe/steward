<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Index\Indexer;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;

/**
 * Before 1.3, one job per post. Jobs queued by the old version can still be
 * waiting when a forum updates, so this hands each one to the new indexer.
 * Remove after 1.3.
 */
class IndexPost extends AbstractJob
{
    public function __construct(
        private int $postId,
        private bool $withdraw = false,
    ) {
        parent::__construct();
    }

    public function handle(Indexer $indexer, LoggerInterface $log): void
    {
        try {
            $this->withdraw ? $indexer->withdraw([$this->postId]) : $indexer->sync([$this->postId]);
        } catch (RelayException $e) {
            $log->info('[steward] relay unavailable, a queued post was not indexed', ['post' => $this->postId]);
        }
    }
}

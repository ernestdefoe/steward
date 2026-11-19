<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Index\Indexer;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;

/**
 * Sends a batch of posts to the hosted index, or withdraws them.
 *
 * One job per batch, not per post: re-tagging a long discussion or running
 * steward:index pushes a handful of jobs instead of one per post, and each
 * batch is read in a single query. Batches are capped at BATCH ids by whoever
 * pushes them, so one job never holds a worker for thousands of calls.
 */
class IndexPosts extends AbstractJob
{
    public const BATCH = 100;

    /** A relay outage is retried a few times, a while apart, then left in failed jobs. */
    public int $tries = 4;

    public int $backoff = 300;

    /** @param list<int> $postIds */
    public function __construct(
        public readonly array $postIds,
        public readonly bool $withdraw = false,
    ) {
        parent::__construct();
    }

    public function handle(Indexer $indexer, LoggerInterface $log): void
    {
        try {
            $this->withdraw ? $indexer->withdraw($this->postIds) : $indexer->sync($this->postIds);
        } catch (RelayException $e) {
            // Stop at the first outage instead of waiting out a timeout per post;
            // sending a batch again is harmless, so the whole batch is retried.
            $log->info('[steward] relay unavailable, indexing retried later', ['posts' => count($this->postIds)]);
            $this->release($this->backoff);
        }
    }
}

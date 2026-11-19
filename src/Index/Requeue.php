<?php

namespace Ernestdefoe\Steward\Index;

use Ernestdefoe\Steward\Job\IndexPosts;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;

/**
 * Puts posts back on the queue for indexing whenever who can read them changes.
 *
 * 🚨 Only ever pushes jobs. The listeners that call this run inside a member's
 * or moderator's request, so the reading and the relay calls happen on the
 * queue: here it is one id query and a push per batch of IndexPosts::BATCH.
 */
class Requeue
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
    ) {
    }

    public function post(int $postId, bool $withdraw = false): void
    {
        // A withdrawal always goes; a post is only added while answers are on.
        if ($postId && $this->hosted() && ($withdraw || $this->answersOn())) {
            $this->queue->push(new IndexPosts([$postId], $withdraw));
        }
    }

    /**
     * 🚨 Who can read a post also changes when its DISCUSSION changes: moved
     * into a staff-only tag, made private, hidden, approved or deleted. Each of
     * those re-sends every post in it, and the job decides afresh whether a
     * guest can read it. A deleted discussion's posts fire no events of their
     * own, so their ids are collected now, while they still exist.
     */
    public function discussion(Discussion $discussion, bool $withdraw = false): void
    {
        // Always queued, even with answers off: a discussion that stopped being
        // public must be withdrawn either way (Indexer adds only while answers are on).
        if (! $discussion->id || ! $this->hosted()) {
            return;
        }

        $ids = CommentPost::query()
            ->where('discussion_id', $discussion->id)
            ->where('type', 'comment')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (array_chunk($ids, IndexPosts::BATCH) as $batch) {
            $this->queue->push(new IndexPosts($batch, $withdraw));
        }
    }

    /**
     * 🚨 Only a forum on hosted retrieval sends posts anywhere. A forum on its
     * own OpenSearch keeps its content: only a question and the passages
     * retrieved for it ever leave.
     */
    private function hosted(): bool
    {
        return (string) $this->settings->get('steward.opensearch_url') === '';
    }

    private function answersOn(): bool
    {
        return (bool) $this->settings->get('steward.answers');
    }
}

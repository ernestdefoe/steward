<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Answers\Asker;
use Ernestdefoe\Steward\Answers\AskResults;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers a member's question on the queue and leaves the result for them.
 *
 * 🚨 An answer is two outbound calls — retrieval, then the relay — that can take
 * half a minute between them. On the queue that time is a worker's, not a PHP
 * worker serving pages, so a busy Ask page cannot slow the rest of the forum.
 */
class AnswerQuestion extends AbstractJob
{
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly string $question,
    ) {
        parent::__construct();
    }

    public function handle(Asker $asker, AskResults $results, LoggerInterface $log): void
    {
        $actor = User::query()->find($this->userId);

        try {
            $result = $actor ? $asker->ask($this->question, $actor) : ['answered' => false, 'reason' => 'unavailable'];
        } catch (Throwable $e) {
            // Logged, and still answered, so the member is not left waiting on
            // a question nobody will ever finish. Retrying would only ask twice.
            $log->warning('[steward] answering failed', ['error' => $e->getMessage()]);
            $result = ['answered' => false, 'reason' => 'unavailable'];
        }

        $results->finish($this->id, $result);
    }
}

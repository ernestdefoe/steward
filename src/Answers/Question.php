<?php

namespace Ernestdefoe\Steward\Answers;

/**
 * A member's question and, once the queue has worked it out, its answer.
 * Lives in the cache (AskResults), not the database: it is read back by the
 * member who asked and then forgotten.
 */
class Question
{
    public string $id = '';

    public string $question = '';

    public string $status = 'pending';

    public ?bool $answered = null;

    public ?string $answer = null;

    public ?string $reason = null;

    /** @var list<array{title: string, url: string}> */
    public array $sources = [];

    /** @param array<string, mixed> $entry */
    public static function fromEntry(string $id, array $entry): self
    {
        $question = new self();
        $question->id = $id;
        $question->status = (string) ($entry['status'] ?? 'pending');
        $question->answered = isset($entry['answered']) ? (bool) $entry['answered'] : null;
        $question->answer = $entry['answer'] ?? null;
        $question->reason = $entry['reason'] ?? null;
        $question->sources = $entry['sources'] ?? [];

        return $question;
    }
}

<?php

namespace Ernestdefoe\Steward\Answers;

final class Answer
{
    private function __construct(
        public readonly string $text,
        /** @var list<Passage> */
        public readonly array $sources,
        public readonly bool $answered,
        public readonly bool $unavailable,
        public readonly bool $exhausted,
    ) {
    }

    /** @param list<Passage> $sources */
    public static function found(string $text, array $sources): self
    {
        return new self($text, $sources, true, false, false);
    }

    public static function dontKnow(): self
    {
        return new self('', [], false, false, false);
    }

    public static function unavailable(bool $exhausted): self
    {
        return new self('', [], false, true, $exhausted);
    }
}

<?php

namespace Ernestdefoe\Steward\Answers;

final class Passage
{
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $url,
        public readonly float $score,
    ) {
    }
}

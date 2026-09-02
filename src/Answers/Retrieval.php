<?php

namespace Ernestdefoe\Steward\Answers;

/**
 * What retrieval found, and whether it is worth answering from.
 *
 * 🚨 The `usable` flag exists because of a measured failure, not a hypothetical.
 *
 * Benchmarking the live index, the nonsense question "banana helicopter
 * tuesday" came back with a real document at score 0.576, against a floor of
 * 0.659 across six genuine questions. Left alone, an assistant answers gibberish
 * from whatever it happened to match, and sounds completely certain doing it.
 *
 * The separation is real but narrow, which is why the threshold is a setting
 * rather than a constant: a different corpus will sit somewhere else, and the
 * only honest default is one measured against real questions.
 */
final class Retrieval
{
    /** @param list<Passage> $passages */
    private function __construct(
        public readonly array $passages,
        public readonly bool $usable,
        public readonly float $topScore,
    ) {
    }

    /** @param list<Passage> $passages */
    public static function from(array $passages, float $threshold): self
    {
        $top = $passages ? max(array_map(fn (Passage $p) => $p->score, $passages)) : 0.0;

        return new self($passages, $passages !== [] && $top >= $threshold, $top);
    }

    public static function nothing(): self
    {
        return new self([], false, 0.0);
    }
}

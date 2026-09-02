<?php

namespace Ernestdefoe\Steward\Moderation;

/**
 * What the pre-filter decided about one post.
 *
 * Three outcomes, not two. "Clear" and "flag" are the cheap certainties; the
 * interesting one is PASS_TO_MODEL, which is the whole reason the pre-filter
 * exists — it decides what is worth spending money on.
 */
final class Verdict
{
    public const CLEAR         = 'clear';
    public const ESCALATE      = 'escalate';   // ask the model
    public const FLAG          = 'flag';       // certain enough to queue without asking

    private function __construct(
        public readonly string $outcome,
        /** Why, in words a moderator can read. Never empty for a non-clear verdict. */
        public readonly array $reasons,
        /** 0..1, only meaningful for FLAG — how sure the cheap pass is. */
        public readonly float $confidence = 0.0,
    ) {
    }

    public static function clear(): self
    {
        return new self(self::CLEAR, []);
    }

    /** @param list<string> $reasons */
    public static function escalate(array $reasons): self
    {
        return new self(self::ESCALATE, $reasons);
    }

    /** @param list<string> $reasons */
    public static function flag(array $reasons, float $confidence): self
    {
        return new self(self::FLAG, $reasons, $confidence);
    }

    public function costsMoney(): bool
    {
        return $this->outcome === self::ESCALATE;
    }
}

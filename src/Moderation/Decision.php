<?php

namespace Ernestdefoe\Steward\Moderation;

/**
 * What actually happens to a post.
 *
 * 🚨 There is no "delete" and no "hide". The strongest thing Steward can do is
 * put something in front of a human, and that is a deliberate ceiling rather
 * than a feature not built yet.
 *
 * An automated moderator that removes posts is wrong occasionally and silent
 * about it always: the author sees their post vanish with no explanation and no
 * appeal, and the forum owner never learns it happened. Queue-and-notify keeps
 * a person in the loop at the only moment where being wrong is expensive.
 */
final class Decision
{
    public const ALLOW    = 'allow';
    public const REVIEW   = 'review';
    public const GUARDIAN = 'guardian';

    private function __construct(
        public readonly string $action,
        /** @var list<string> Plain words a moderator can read. */
        public readonly array $reasons,
        public readonly string $source,
        public readonly float $confidence,
        /** True when we let it through WITHOUT looking — an outage or an empty allowance. */
        public readonly bool $unscreened,
    ) {
    }

    public static function allow(string $why, bool $unscreened = false): self
    {
        return new self(self::ALLOW, [$why], 'steward', 0.0, $unscreened);
    }

    /** @param list<string> $reasons */
    public static function review(array $reasons, string $source, float $confidence): self
    {
        return new self(self::REVIEW, $reasons, $source, $confidence, false);
    }

    /** @param list<string> $reasons */
    public static function escalateToGuardian(array $reasons): self
    {
        return new self(self::GUARDIAN, $reasons, 'model', 1.0, false);
    }

    public function needsAHuman(): bool
    {
        return $this->action !== self::ALLOW;
    }
}

<?php

namespace Ernestdefoe\Steward\Relay;

/**
 * What the relay told us about how much is left.
 *
 * 🚨 Carried on every response, not fetched separately. A site needs to warn
 * before it stops working — the whole complaint about credit products is that
 * they die without notice — and a separate "check my balance" call would be
 * one more thing to get out of date or forget to make.
 */
final class Quota
{
    public function __construct(
        public readonly ?int $remaining = null,
        public readonly ?int $limit = null,
        /** Unix timestamp when the allowance resets, if the relay said. */
        public readonly ?int $resetsAt = null,
    ) {
    }

    public static function fromHeaders(array $headers): self
    {
        $get = static function (string $name) use ($headers): ?int {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    $value = is_array($v) ? ($v[0] ?? null) : $v;

                    return is_numeric($value) ? (int) $value : null;
                }
            }

            return null;
        };

        return new self(
            $get('X-Steward-Remaining'),
            $get('X-Steward-Limit'),
            $get('X-Steward-Reset'),
        );
    }

    /** True once the site is close enough that an admin should be told. */
    public function nearlyOut(float $threshold = 0.1): bool
    {
        if ($this->remaining === null || !$this->limit) {
            return false;
        }

        return $this->remaining / $this->limit <= $threshold;
    }
}

<?php

namespace Ernestdefoe\Steward\Relay;

use RuntimeException;

/**
 * Something went wrong talking to the hosted service.
 *
 * 🚨 `exhausted` is separated from every other failure on purpose. Running out
 * of allowance is a normal, expected state with a specific thing to tell the
 * admin — it is not an error to retry, and it must never be reported to a
 * member as "something went wrong".
 */
class RelayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $exhausted = false,
        public readonly bool $retryable = false,
        public readonly ?Quota $quota = null,
        // What went wrong, as a key the admin's usage panel translates.
        public readonly string $reason = 'unreachable',
    ) {
        parent::__construct($message);
    }
}

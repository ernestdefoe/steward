<?php

namespace Ernestdefoe\Steward\Job;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Fetches this forum's usage from the relay and keeps it for the admin page.
 *
 * On the queue, so opening Steward's settings never waits on the relay.
 */
class RefreshUsage extends AbstractJob
{
    public const CACHE_KEY = 'steward.usage';
    public const FRESH_SECONDS = 300;

    public function handle(RelayClient $relay, Cache $cache): void
    {
        try {
            $usage = ['connected' => true] + $relay->post('v1/usage', [])['data'];
        } catch (RelayException $e) {
            // Kept as a reason key; the controller translates it for whoever asks.
            $usage = ['connected' => false, 'reason' => $e->reason];
        }

        $cache->put(self::CACHE_KEY, $usage, self::FRESH_SECONDS);
    }
}

<?php

namespace Ernestdefoe\Steward\Listener;

use Ernestdefoe\Steward\Job\RefreshUsage;
use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A new site key (or relay) makes the kept usage wrong: forget it, so the
 * admin page fetches it afresh instead of showing the old key's answer.
 */
class ForgetUsage
{
    public function __construct(private Cache $cache)
    {
    }

    public function handle(Saved $event): void
    {
        if (array_key_exists('steward.site_key', $event->settings) || array_key_exists('steward.relay_url', $event->settings)) {
            $this->cache->forget(RefreshUsage::CACHE_KEY);
            $this->cache->forget(RefreshUsage::CACHE_KEY.'.queued');
        }
    }
}

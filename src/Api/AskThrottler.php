<?php

namespace Ernestdefoe\Steward\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Caps how often one member can ask.
 *
 * 🚨 An ask is two outbound calls — retrieval, then the relay — that can hold a
 * PHP worker for half a minute between them. A shared host has a handful of
 * workers, so a member (or a script with a member's cookie) pressing Ask in a
 * loop takes the whole forum down for everyone else, and spends the relay
 * quota doing it. Six a minute is far beyond anyone reading the answers.
 *
 * Fixed window, best-effort via the cache. Returns null (no opinion) for every
 * other route, so it costs nothing on the rest of the API.
 */
class AskThrottler
{
    public const MAX_ASKS = 6;
    public const WINDOW_SECONDS = 60;

    public function __construct(private Cache $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'steward.ask') {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return null; // the controller turns guests away
        }

        $key = 'steward.ask.'.$actor->id.'.'.intdiv(time(), self::WINDOW_SECONDS);
        $count = (int) $this->cache->get($key, 0);

        if ($count >= self::MAX_ASKS) {
            return true;
        }

        $this->cache->put($key, $count + 1, self::WINDOW_SECONDS);

        return null;
    }
}

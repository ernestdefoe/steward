<?php

namespace Ernestdefoe\Steward\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Caps how often one member can ask.
 *
 * 🚨 An ask is two outbound calls — retrieval, then the relay — that can take
 * half a minute. They run on the queue, but a member (or a script with a
 * member's cookie) pressing Ask in a loop would still fill the queue, and on a
 * forum whose queue runs jobs straight away it would tie up the PHP workers
 * serving pages; either way it spends the relay quota. Six a minute is far
 * beyond anyone reading the answers.
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
        if ($request->getAttribute('routeName') !== 'steward-questions.create') {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return null; // the steward-questions endpoint is for signed-in members only
        }

        $key = 'steward.ask.'.$actor->id.'.'.intdiv(time(), self::WINDOW_SECONDS);

        // add() then increment(): atomic on every cache store, so two asks at
        // the same moment cannot both read the same count.
        $this->cache->add($key, 0, self::WINDOW_SECONDS);

        return (int) $this->cache->increment($key) > self::MAX_ASKS ? true : null;
    }
}

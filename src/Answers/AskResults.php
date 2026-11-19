<?php

namespace Ernestdefoe\Steward\Answers;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Where a queued question's answer waits for the member who asked it.
 *
 * Each entry records who asked, so only that member can read it back, and
 * expires on its own: nothing to prune.
 */
class AskResults
{
    public const TTL_SECONDS = 600;

    public function __construct(private Cache $cache)
    {
    }

    public function start(string $id, int $userId): void
    {
        $this->cache->put(self::key($id), ['user' => $userId, 'status' => 'pending'], self::TTL_SECONDS);
    }

    /** @param array<string, mixed> $result */
    public function finish(string $id, array $result): void
    {
        $entry = $this->cache->get(self::key($id));

        if (is_array($entry)) {
            $this->cache->put(self::key($id), ['user' => $entry['user'], 'status' => 'done'] + $result, self::TTL_SECONDS);
        }
    }

    /** @return array<string, mixed>|null null when it is unknown, expired or someone else's */
    public function read(string $id, User $actor): ?array
    {
        $entry = $this->cache->get(self::key($id));

        if (! is_array($entry) || (int) $entry['user'] !== (int) $actor->id) {
            return null;
        }

        unset($entry['user']);

        return $entry;
    }

    private static function key(string $id): string
    {
        return 'steward.ask.result.'.$id;
    }
}

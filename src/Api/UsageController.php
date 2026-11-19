<?php

namespace Ernestdefoe\Steward\Api;

use Ernestdefoe\Steward\Job\RefreshUsage;
use Ernestdefoe\Steward\Relay\RelayClient;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/steward/usage — this forum's usage, for the admin page.
 *
 * 🚨 Fetched by the server, never by the browser: the site key is a bearer
 * credential bound to this domain, and putting it in JavaScript hands it to
 * anyone who opens dev tools. The relay call itself runs on the queue
 * (RefreshUsage); this returns what it found, or `pending` until it has.
 */
class UsageController implements RequestHandlerInterface
{
    public function __construct(
        private RelayClient $relay,
        private TranslatorInterface $translator,
        private Cache $cache,
        private Queue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->relay->configured()) {
            return new JsonResponse(['connected' => false]);
        }

        // One refresh at a time, however many admins have the page open.
        if (! $this->cache->has(RefreshUsage::CACHE_KEY) && $this->cache->add(RefreshUsage::CACHE_KEY.'.queued', true, 60)) {
            $this->queue->push(new RefreshUsage());
        }

        $usage = $this->cache->get(RefreshUsage::CACHE_KEY);

        if (! is_array($usage)) {
            return new JsonResponse(['pending' => true]);
        }

        if (isset($usage['reason'])) {
            $usage['error'] = $this->translator->trans('ernestdefoe-steward.api.relay.'.$usage['reason']);
            unset($usage['reason']);
        }

        return new JsonResponse($usage);
    }
}

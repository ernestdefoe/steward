<?php

namespace Ernestdefoe\Steward\Api;

use Ernestdefoe\Steward\Relay\RelayClient;
use Ernestdefoe\Steward\Relay\RelayException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/steward/usage — proxies the relay so the admin page can show a
 * forum its own usage without anyone leaving the site.
 *
 * 🚨 Proxied rather than called from the browser. The site key must never
 * reach the front end: it is a bearer credential bound to this domain, and
 * putting it in JavaScript hands it to anyone who opens dev tools.
 */
class UsageController implements RequestHandlerInterface
{
    public function __construct(
        private RelayClient $relay
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->relay->configured()) {
            return new JsonResponse(['connected' => false], 200);
        }

        try {
            $res = $this->relay->post('v1/usage', []);
        } catch (RelayException $e) {
            return new JsonResponse([
                'connected' => false,
                'error'     => $e->getMessage(),
            ], 200);
        }

        return new JsonResponse(['connected' => true] + $res['data']);
    }
}

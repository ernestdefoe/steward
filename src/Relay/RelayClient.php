<?php

namespace Ernestdefoe\Steward\Relay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only thing in this extension that talks to the outside world.
 *
 * 🚨 There is no model provider setting and no API key field anywhere in
 * Steward, and that is the product. A site sends its own key — the one the
 * client area issued and bound to this domain — and the relay decides which
 * model to use, enforces the quota and does the billing. AI Helper is the
 * extension for people who want to bring their own key; this one exists for
 * people who do not want an AI account at all.
 *
 * Two consequences worth being explicit about:
 *
 *  - The site never learns which model answered. That is deliberate: pinning
 *    the model per tier is what keeps the tier profitable, and a setting the
 *    customer could change would be a setting they could change to Opus.
 *  - The site cannot alter its own usage count. Metering lives behind the key.
 */
class RelayClient
{
    /** Where the relay publishes its endpoints, relative to the site root. */
    private const PREFIX = '/api/steward/';

    public function __construct(
        private string $baseUrl,
        private string $siteKey,
        private LoggerInterface $log,
        /*
         * 🚨 This forum's own URL, sent as Origin on every call.
         *
         * A site key is bound to one origin and the relay re-checks that
         * binding on EVERY request, not just at binding time — so a client that
         * sends no Origin is refused every time, which is what a stolen key
         * looks like and is exactly what this forum looked like until now.
         */
        private string $ownUrl,
        // Built by StewardServiceProvider with its timeouts.
        private Client $http,
    ) {
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->siteKey !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{data: array<string,mixed>}
     */
    public function post(string $path, array $payload): array
    {
        if (! $this->configured()) {
            throw new RelayException('Steward is not connected — no site key.', reason: 'not_connected');
        }

        /*
         * 🚨 The relay's prefix lives HERE, in one place, not in each caller.
         *
         * Callers pass 'v1/usage'; the relay publishes /api/steward/v1/usage.
         * When those two disagreed, every call 404'd — and a 404 arrives
         * looking exactly like a refusal or an outage, so nothing about a path
         * mismatch announces itself.
         */
        $url = rtrim($this->baseUrl, '/').self::PREFIX.ltrim($path, '/');

        try {
            $res = $this->http->post($url, [
                'headers' => array_filter([
                    'Authorization' => 'Bearer '.$this->siteKey,
                    'Accept' => 'application/json',
                    'Origin' => $this->ownUrl !== '' ? rtrim($this->ownUrl, '/') : null,
                ]),
                'json' => $payload,
            ]);
        } catch (ClientException $e) {
            throw self::refusal($e);
        } catch (ServerException|ConnectException) {
            // Their side, or the network. Worth trying again later; never worth
            // showing to a member.
            throw new RelayException('The AI service is unavailable.', retryable: true, reason: 'unavailable');
        } catch (Throwable $e) {
            $this->log->warning('[steward] relay call failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new RelayException('The AI service could not be reached.', retryable: true);
        }

        $body = json_decode((string) $res->getBody(), true);

        if (! is_array($body)) {
            throw new RelayException('The AI service returned something unreadable.', reason: 'unreadable');
        }

        return ['data' => $body];
    }

    /**
     * 🚨 402 and 429 are the allowance, not a fault.
     *
     * They get their own flag so callers can degrade quietly — a member should
     * never see a red error because the forum owner's month ran out. 401/403
     * mean the key is wrong or revoked, which IS an admin problem and is not
     * retryable.
     */
    private static function refusal(ClientException $e): RelayException
    {
        $status = $e->getResponse()->getStatusCode();

        if ($status === 402 || $status === 429) {
            return new RelayException('Allowance used up for this period.', exhausted: true, reason: 'exhausted');
        }

        if ($status === 401 || $status === 403) {
            return new RelayException('This site key was rejected. It may have been revoked or bound to another domain.', reason: 'key_rejected');
        }

        return new RelayException('The AI service refused the request.', reason: 'refused');
    }
}

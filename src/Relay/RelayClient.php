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
    private Client $http;

    public function __construct(
        private string $baseUrl,
        private string $siteKey,
        private LoggerInterface $log,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            // Generous but finite. A hung relay must not hold a web request open.
            'timeout'         => 25,
            'connect_timeout' => 6,
            'http_errors'     => true,
        ]);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->siteKey !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{data: array<string,mixed>, quota: Quota}
     */
    public function post(string $path, array $payload): array
    {
        if (! $this->configured()) {
            throw new RelayException('Steward is not connected — no site key.');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $res = $this->http->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->siteKey,
                    'Accept'        => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (ClientException $e) {
            $status  = $e->getResponse()->getStatusCode();
            $quota   = Quota::fromHeaders($e->getResponse()->getHeaders());

            /*
             * 🚨 402 and 429 are the allowance, not a fault.
             *
             * They get their own flag so callers can degrade quietly — a
             * member should never see a red error because the forum owner's
             * month ran out. 401/403 mean the key is wrong or revoked, which
             * IS an admin problem and is not retryable.
             */
            if ($status === 402 || $status === 429) {
                throw new RelayException('Allowance used up for this period.', exhausted: true, quota: $quota);
            }

            throw new RelayException(
                $status === 401 || $status === 403
                    ? 'This site key was rejected. It may have been revoked or bound to another domain.'
                    : 'The AI service refused the request.',
                quota: $quota
            );
        } catch (ServerException|ConnectException $e) {
            // Their side, or the network. Worth trying again later; never worth
            // showing to a member.
            throw new RelayException('The AI service is unavailable.', retryable: true);
        } catch (Throwable $e) {
            $this->log->warning('[steward] relay call failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RelayException('The AI service could not be reached.', retryable: true);
        }

        $body = json_decode((string) $res->getBody(), true);

        if (! is_array($body)) {
            throw new RelayException('The AI service returned something unreadable.');
        }

        return ['data' => $body, 'quota' => Quota::fromHeaders($res->getHeaders())];
    }
}

<?php

namespace Ernestdefoe\Steward\Answers;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Local mode: the customer's own OpenSearch does the retrieval, and only the
 * question and the passages it already holds ever leave their server.
 *
 * Harder to sell — they need a cluster with a deployed embedding model — but
 * their members' words never become our problem, which for some forums is the
 * only version they can adopt at all.
 *
 * 🚨 A neural query, not a keyword one. Benchmarked on a live 473-document
 * index the two are not close: semantic found the right page for "change my
 * password" (Passkeys), "upload an avatar" (Profile Photo Gallery) and "what is
 * Convoro" (Welcome to Convoro), where keyword returned Clubs, "Handling an
 * upload safely" and "What an application is" respectively.
 */
class LocalRetrieval implements RetrievalProvider
{
    private Client $http;

    public function __construct(
        private string $baseUrl,
        private string $index,
        private string $modelId,
        private string $username,
        private string $password,
        private float $threshold,
        private LoggerInterface $log,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => 12,
            'connect_timeout' => 5,
            // Self-signed certificates are the norm on a private cluster.
            'verify'          => false,
        ]);
    }

    public function available(): bool
    {
        return $this->baseUrl !== '' && $this->index !== '' && $this->modelId !== '';
    }

    public function find(string $question, int $limit = 5): Retrieval
    {
        if (! $this->available()) {
            return Retrieval::nothing();
        }

        try {
            $res = $this->http->post(
                rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->index) . '/_search',
                [
                    'auth' => $this->username !== '' ? [$this->username, $this->password] : null,
                    'json' => [
                        'size'    => $limit,
                        '_source' => ['title', 'body', 'url'],
                        'query'   => ['neural' => ['body_embedding' => [
                            'query_text' => $question,
                            'model_id'   => $this->modelId,
                            'k'          => $limit,
                        ]]],
                    ],
                ]
            );
        } catch (Throwable $e) {
            /*
             * Their cluster, their outage. Returning nothing means the
             * assistant says it could not find anything — which is true, and
             * far better than an error in front of a member.
             */
            $this->log->info('[steward] local retrieval unavailable', ['error' => $e->getMessage()]);

            return Retrieval::nothing();
        }

        $body = json_decode((string) $res->getBody(), true);
        $hits = $body['hits']['hits'] ?? [];

        $passages = [];
        foreach ($hits as $hit) {
            $src = $hit['_source'] ?? [];
            $passages[] = new Passage(
                (string) ($src['title'] ?? ''),
                (string) ($src['body'] ?? ''),
                (string) ($src['url'] ?? ''),
                (float) ($hit['_score'] ?? 0),
            );
        }

        return Retrieval::from($passages, $this->threshold);
    }
}

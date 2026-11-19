<?php

namespace Ernestdefoe\Steward\Tests\integration;

use Ernestdefoe\Steward\Answers\HostedRetrieval;
use Ernestdefoe\Steward\Answers\LocalRetrieval;
use Ernestdefoe\Steward\Answers\RetrievalProvider;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Test;

class ProviderTest extends TestCase
{
    private function verify(): mixed
    {
        $retrieval = $this->app()->getContainer()->make(RetrievalProvider::class);
        $this->assertInstanceOf(LocalRetrieval::class, $retrieval);

        /** @var Client $http */
        $http = (new \ReflectionProperty(LocalRetrieval::class, 'http'))->getValue($retrieval);

        return $http->getConfig('verify');
    }

    #[Test]
    public function a_forum_without_its_own_cluster_uses_hosted_retrieval()
    {
        $this->extension('ernestdefoe-steward');

        $this->assertInstanceOf(HostedRetrieval::class, $this->app()->getContainer()->make(RetrievalProvider::class));
    }

    #[Test]
    public function its_own_cluster_is_reached_with_certificates_verified()
    {
        $this->extension('ernestdefoe-steward');
        $this->setting('steward.opensearch_url', 'https://search.invalid');

        $this->assertTrue($this->verify());
    }

    #[Test]
    public function a_self_signed_cluster_is_verified_against_its_own_ca()
    {
        $this->extension('ernestdefoe-steward');
        $this->setting('steward.opensearch_url', 'https://search.invalid');
        $this->setting('steward.opensearch_ca', '/etc/ssl/opensearch-ca.pem');

        $this->assertSame('/etc/ssl/opensearch-ca.pem', $this->verify());
    }
}

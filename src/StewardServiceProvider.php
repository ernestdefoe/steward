<?php

namespace Ernestdefoe\Steward;

use Ernestdefoe\Steward\Answers\Answerer;
use Ernestdefoe\Steward\Answers\HostedRetrieval;
use Ernestdefoe\Steward\Answers\LocalRetrieval;
use Ernestdefoe\Steward\Answers\RetrievalProvider;
use Ernestdefoe\Steward\Moderation\PreFilter;
use Ernestdefoe\Steward\Moderation\Screener;
use Ernestdefoe\Steward\Relay\RelayClient;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * 🚨 Everything is wired from settings, and there is no model provider among
 * them.
 *
 * A site sends its own key; the relay picks the model. That is not a missing
 * feature — which model runs is a pricing decision, so it lives with whoever
 * carries the bill.
 *
 * The HTTP clients are built here, each with its own timeouts, and handed to
 * the classes that use them; nothing is bound globally, so no other extension's
 * Guzzle client changes.
 */
class StewardServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RelayClient::class, fn (Container $c) => $this->relay($c));
        $this->container->singleton(RetrievalProvider::class, fn (Container $c) => $this->retrieval($c));

        $this->container->singleton(PreFilter::class, function (Container $c) {
            $settings = $c->make(SettingsRepositoryInterface::class);

            return new PreFilter(
                $c->make(TranslatorInterface::class),
                (int) $settings->get('steward.trusted_posts'),
                (int) $settings->get('steward.new_account_days'),
            );
        });

        $this->container->singleton(Screener::class, fn (Container $c) => new Screener(
            $c->make(PreFilter::class),
            $c->make(RelayClient::class),
            $c->make(LoggerInterface::class),
            $c->make(TranslatorInterface::class),
        ));

        // The answer threshold is applied by retrieval (Retrieval::from), so
        // the answerer needs only the relay.
        $this->container->singleton(Answerer::class, fn (Container $c) => new Answerer($c->make(RelayClient::class)));
    }

    private function relay(Container $c): RelayClient
    {
        $settings = $c->make(SettingsRepositoryInterface::class);

        return new RelayClient(
            (string) $settings->get('steward.relay_url'),
            (string) $settings->get('steward.site_key'),
            $c->make(LoggerInterface::class),
            // The forum's canonical URL — this is the identity the key is bound
            // to, so it comes from config, never from a request.
            (string) $c->make(Config::class)->url(),
            // Generous but finite. Calls run on the queue, so a hung relay holds
            // a worker for at most this long, never a member's request.
            new Client(['timeout' => 25, 'connect_timeout' => 6, 'http_errors' => true]),
        );
    }

    /**
     * 🚨 The site does not choose its mode — it offers what it can.
     *
     * If an OpenSearch cluster is configured here, retrieval runs there and
     * only the question and its own passages ever leave. If it is not, the site
     * sends the question alone, and the RELAY decides whether it is entitled to
     * hosted retrieval by looking at the tier behind the key. Leaving the
     * cluster blank asks for hosted, and asking is not the same as being allowed.
     */
    private function retrieval(Container $c): RetrievalProvider
    {
        $settings = $c->make(SettingsRepositoryInterface::class);
        $url = (string) $settings->get('steward.opensearch_url');
        $threshold = (float) $settings->get('steward.answer_threshold');

        if ($url === '') {
            return new HostedRetrieval($threshold);
        }

        $ca = (string) $settings->get('steward.opensearch_ca');

        return new LocalRetrieval(
            $url,
            (string) $settings->get('steward.opensearch_index'),
            (string) $settings->get('steward.opensearch_model'),
            (string) $settings->get('steward.opensearch_user'),
            (string) $settings->get('steward.opensearch_pass'),
            $threshold,
            $c->make(LoggerInterface::class),
            /*
             * 🚨 Certificates are always verified. A cluster with a self-signed
             * certificate names its CA bundle in steward.opensearch_ca; turning
             * verification off would let anyone on the network path feed the
             * assistant passages of their own.
             */
            new Client(['timeout' => 12, 'connect_timeout' => 5, 'verify' => $ca !== '' ? $ca : true]),
        );
    }
}

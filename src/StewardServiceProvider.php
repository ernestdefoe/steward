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
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * 🚨 Everything is wired from settings, and there is no model provider among
 * them.
 *
 * A site sends its own key; the relay picks the model. That is not a missing
 * feature — a setting the customer could change would be a setting they could
 * change to Opus, and Opus on a metered tier costs more than the subscription
 * it is sold under. Which model runs is a pricing decision, so it lives with
 * whoever carries the bill.
 */
class StewardServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RelayClient::class, function ($c) {
            $settings = $c->make(SettingsRepositoryInterface::class);

            return new RelayClient(
                (string) $settings->get('steward.relay_url'),
                (string) $settings->get('steward.site_key'),
                $c->make(LoggerInterface::class),
                // The forum's canonical URL — this is the identity the key is
                // bound to, so it comes from config, never from a request.
                (string) $c->make('flarum.config')->url(),
            );
        });

        $this->container->singleton(PreFilter::class, function ($c) {
            $settings = $c->make(SettingsRepositoryInterface::class);

            return new PreFilter(
                (int) ($settings->get('steward.trusted_posts') ?: 25),
                (int) ($settings->get('steward.new_account_days') ?: 7),
            );
        });

        $this->container->singleton(Screener::class, fn ($c) => new Screener(
            $c->make(PreFilter::class),
            $c->make(RelayClient::class),
            $c->make(LoggerInterface::class),
        ));

        /*
         * 🚨 The site does not choose its mode — it offers what it can.
         *
         * If an OpenSearch cluster is configured here, retrieval runs there and
         * only the question and its own passages ever leave. If it is not, the
         * site sends the question alone, and the RELAY decides whether it is
         * entitled to hosted retrieval by looking at the tier behind the key.
         * So there is no mode to negotiate and nothing here to spoof: leaving
         * the cluster blank asks for hosted, and asking is not the same as
         * being allowed.
         */
        $this->container->singleton(RetrievalProvider::class, function ($c) {
            $settings = $c->make(SettingsRepositoryInterface::class);
            $url = (string) $settings->get('steward.opensearch_url');

            if ($url === '') {
                return new HostedRetrieval();
            }

            return new LocalRetrieval(
                $url,
                (string) $settings->get('steward.opensearch_index'),
                (string) $settings->get('steward.opensearch_model'),
                (string) $settings->get('steward.opensearch_user'),
                (string) $settings->get('steward.opensearch_pass'),
                (float) ($settings->get('steward.answer_threshold') ?: 0.62),
                $c->make(LoggerInterface::class),
            );
        });

        $this->container->singleton(Answerer::class, function ($c) {
            $settings = $c->make(SettingsRepositoryInterface::class);

            return new Answerer(
                $c->make(RelayClient::class),
                (float) ($settings->get('steward.answer_threshold') ?: 0.62),
            );
        });
    }
}

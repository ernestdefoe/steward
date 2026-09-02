<?php

namespace Ernestdefoe\Steward;

use Ernestdefoe\Steward\Answers\Answerer;
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

        $this->container->singleton(Answerer::class, function ($c) {
            $settings = $c->make(SettingsRepositoryInterface::class);

            return new Answerer(
                $c->make(RelayClient::class),
                (float) ($settings->get('steward.answer_threshold') ?: 0.62),
            );
        });
    }
}

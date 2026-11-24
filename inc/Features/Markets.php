<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Admin\MarketsSettings;
use KoperasiKita\Api\Hooks;
use KoperasiKita\Providers\ManualSnapshotProvider;
use KoperasiKita\Providers\MarketPrice;
use KoperasiKita\Providers\MarketPriceProvider;

/**
 * Markets data layer: gold, stock and crypto prices.
 *
 * This class is the thin data façade — catalogue (markets, labels,
 * symbols), settings storage, provider resolution and formatting.
 * The provider contracts live in KoperasiKita\Providers, and the
 * Settings -> Markets admin screen lives in MarketsSettings.
 *
 * Provider choice (which provider supplies each market) and the manual
 * snapshots live in the `kk_markets_settings` option, edited on the
 * Settings -> Markets screen — never in the Customizer and never hardcoded
 * in a template. When the feature is disabled nothing here hooks in at all:
 * no settings page, no settings registration, no frontend output.
 */
final class Markets
{
    public const OPTION = 'kk_markets_settings';

    /**
     * Kept for backwards compatibility; the canonical name is
     * Hooks::MARKETS_PROVIDERS.
     */
    public const PROVIDERS_FILTER = Hooks::MARKETS_PROVIDERS;

    /** @return array<int, string> Market slugs in display order. */
    public static function markets(): array
    {
        return ['gold', 'stock', 'crypto'];
    }

    /**
     * Market slug => translatable label. Third parties may add or
     * rename markets through the `koperasikita/markets/labels` filter.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = apply_filters(Hooks::MARKETS_LABELS, [
            'gold' => __('Gold', 'koperasikita'),
            'stock' => __('Stocks', 'koperasikita'),
            'crypto' => __('Crypto', 'koperasikita'),
        ]);

        if (!is_array($labels)) {
            return [];
        }

        $clean = [];

        foreach ($labels as $slug => $label) {
            if (is_string($slug) && $slug !== '' && is_string($label)) {
                $clean[$slug] = $label;
            }
        }

        return $clean;
    }

    /**
     * Known symbols per market (slug => label). Only these symbols can ever
     * carry a snapshot, which keeps the settings screen and the widgets in
     * agreement about what exists. Third parties may add symbols for a
     * market through the `koperasikita/markets/symbols` filter, which
     * receives the symbols array and the market slug.
     *
     * @return array<string, string>
     */
    public static function symbols(string $market): array
    {
        if ($market === 'gold') {
            $symbols = array_merge(
                ['spot' => __('Gold spot', 'koperasikita')],
                Gold::brands()
            );
        } elseif ($market === 'stock') {
            $symbols = [
                'ihsg' => 'IHSG',
                'lq45' => 'LQ45',
            ];
        } elseif ($market === 'crypto') {
            $symbols = [
                'btc' => 'Bitcoin',
                'eth' => 'Ethereum',
                'sol' => 'Solana',
                'usdt' => 'Tether',
                'xrp' => 'XRP',
            ];
        } else {
            $symbols = [];
        }

        $symbols = apply_filters(Hooks::MARKETS_SYMBOLS, $symbols, $market);

        if (!is_array($symbols)) {
            return [];
        }

        $clean = [];

        foreach ($symbols as $slug => $label) {
            if (is_string($slug) && $slug !== '' && is_string($label)) {
                $clean[$slug] = $label;
            }
        }

        return $clean;
    }

    public static function currency(string $market): string
    {
        return 'IDR';
    }

    public static function isEnabled(): bool
    {
        return Features::isEnabled('markets');
    }

    /**
     * Full settings array, merged over the defaults so callers can always
     * read `providers` and `snapshots` without isset() guards.
     *
     * @return array{providers: array<string, string>, snapshots: array<string, array<string, array{price?: float, change?: float, updated?: int}>>}
     */
    public static function settings(): array
    {
        $defaults = [
            'providers' => [
                'gold' => 'manual',
                'stock' => 'manual',
                'crypto' => 'manual',
            ],
            'snapshots' => [],
        ];

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        $settings = array_merge($defaults, $stored);
        $settings['providers'] = is_array($settings['providers'])
            ? array_merge($defaults['providers'], $settings['providers'])
            : $defaults['providers'];
        $settings['snapshots'] = is_array($settings['snapshots']) ? $settings['snapshots'] : [];

        return $settings;
    }

    /** @return array<string, MarketPriceProvider> Providers keyed by id. */
    public static function providers(): array
    {
        $providers = apply_filters(Hooks::MARKETS_PROVIDERS, [
            'manual' => new ManualSnapshotProvider(),
        ]);

        if (!is_array($providers)) {
            return ['manual' => new ManualSnapshotProvider()];
        }

        $valid = [];
        foreach ($providers as $id => $provider) {
            if ($provider instanceof MarketPriceProvider && is_string($id) && $id !== '') {
                $valid[$id] = $provider;
            }
        }

        if (!isset($valid['manual'])) {
            $valid['manual'] = new ManualSnapshotProvider();
        }

        return $valid;
    }

    public static function providerFor(string $market): MarketPriceProvider
    {
        $providers = self::providers();
        $chosen = self::settings()['providers'][$market] ?? 'manual';

        if (isset($providers[$chosen]) && $providers[$chosen]->supports($market)) {
            return $providers[$chosen];
        }

        foreach ($providers as $provider) {
            if ($provider->supports($market)) {
                return $provider;
            }
        }

        return $providers['manual'];
    }

    /**
     * Prices for one market, keyed by symbol. Only symbols with a real
     * snapshot price are returned — an empty array means "no data", and the
     * caller renders an honest empty state.
     *
     * @return array<string, MarketPrice>
     */
    public static function prices(string $market): array
    {
        if (!self::isEnabled() || !in_array($market, self::markets(), true)) {
            return [];
        }

        return self::providerFor($market)->prices($market);
    }

    public static function formatPrice(float $price, string $currency = 'IDR'): string
    {
        if ($currency === 'IDR') {
            return 'Rp' . number_format($price, 0, ',', '.');
        }

        return number_format($price, 2, '.', ',') . ' ' . $currency;
    }

    public static function formatChange(?float $change): string
    {
        if ($change === null) {
            return '';
        }

        $sign = $change > 0 ? '+' : '';
        return $sign . number_format($change, 2, '.', ',') . '%';
    }

    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        MarketsSettings::register();
    }
}

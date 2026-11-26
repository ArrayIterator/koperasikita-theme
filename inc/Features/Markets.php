<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Admin\MarketsSettings;
use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Hooks;
use KoperasiKita\Providers\AbstractLiveProvider;
use KoperasiKita\Providers\CoinGeckoProvider;
use KoperasiKita\Providers\IndodaxProvider;
use KoperasiKita\Providers\ManualSnapshotProvider;
use KoperasiKita\Providers\MarketPrice;
use KoperasiKita\Providers\MarketPriceProvider;
use KoperasiKita\Providers\ScrapedGoldProvider;
use KoperasiKita\Providers\XausProvider;
use KoperasiKita\Providers\YahooStockProvider;

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
     * read `providers`, `snapshots` and `enabled` without isset() guards.
     *
     * @return array{providers: array<string, string>, snapshots: array<string, array<string, array{price?: float, change?: float, updated?: int}>>, enabled: array<string, array<string, bool>>}
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
            'enabled' => [],
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
        $settings['enabled'] = is_array($settings['enabled']) ? $settings['enabled'] : [];

        return $settings;
    }

    /**
     * Symbols shown for a market: the admin's picker choice for the
     * market's current provider. No stored choice (fresh install, or a
     * provider switched before the picker was opened) means every
     * symbol the provider supplies — nothing is hidden by default.
     *
     * @return array<string, string> Enabled symbols (slug => label).
     */
    public static function enabledSymbols(string $market): array
    {
        $supplies = self::providerFor($market)->supplies($market);

        if ($supplies === []) {
            return [];
        }

        $stored = self::settings()['enabled'][$market] ?? null;

        if (!is_array($stored) || $stored === []) {
            return $supplies;
        }

        $enabled = [];

        foreach ($supplies as $symbol => $label) {
            if (!empty($stored[$symbol])) {
                $enabled[$symbol] = $label;
            }
        }

        // An admin who unchecks everything gets the provider's full
        // list back: an empty picker must not blank the whole market.
        return $enabled !== [] ? $enabled : $supplies;
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
     * caller renders an honest empty state. Only the symbols the admin
     * picked in the provider picker are returned (see enabledSymbols()).
     *
     * @return array<string, MarketPrice>
     */
    public static function prices(string $market): array
    {
        if (!self::isEnabled() || !in_array($market, self::markets(), true)) {
            return [];
        }

        $enabled = self::enabledSymbols($market);

        if ($enabled === []) {
            return [];
        }

        $prices = self::providerFor($market)->prices($market);

        return array_intersect_key($prices, $enabled);
    }

    /**
     * Markets for the homepage strip, in the same shape the widget passes
     * to template-parts/markets/strip.php. An empty array means "do not
     * render the strip at all": the Markets feature is disabled, or the
     * homepage toggle (Customizer -> Homepage Layout -> Show Market Strip
     * on Homepage, default on) is off. When the strip does render, the
     * template itself shows an honest empty state if no market has data
     * yet — the same as the widget.
     *
     * @return array<string, array{label: string, prices: array<string, MarketPrice>}>
     */
    public static function homepageStripMarkets(): array
    {
        if (!self::isEnabled() || !(bool) get_theme_mod('kk_homepage_markets_strip', true)) {
            return [];
        }

        $markets = [];
        foreach (self::markets() as $market) {
            $markets[$market] = [
                'label' => self::labels()[$market] ?? $market,
                'prices' => self::prices($market),
                // Client-side refresh info: the provider id and the
                // public API URL the browser may call directly (null
                // when the provider is only served through the backend
                // endpoint). The strip template prints these as data
                // attributes for src/ts/markets.ts.
                'client' => self::clientInfo($market),
            ];
        }

        return $markets;
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

        // Live price providers (all free, no API key). Registered
        // through the providers filter so they appear in the
        // Settings -> Markets provider dropdown for their market,
        // next to Manual. The default stays Manual until the admin
        // picks one. Each provider caches in a transient (15 min)
        // and falls back to its last good value when the upstream
        // is unreachable.
        add_filter(Hooks::MARKETS_PROVIDERS, static function (array $providers): array {
            $providers['xaus'] = new XausProvider();
            $providers['scraped-gold'] = new ScrapedGoldProvider();
            $providers['coingecko'] = new CoinGeckoProvider();
            $providers['indodax'] = new IndodaxProvider();
            $providers['yahoo'] = new YahooStockProvider();

            return $providers;
        });

        // Read-only prices endpoint for the client-side refresh
        // (src/ts/markets.ts). The browser calls it only when the
        // provider has no direct client API (Yahoo, Indodax) or the
        // direct call failed; the localStorage cache in the browser
        // keeps it from being called on every page load.
        Ajax::add('kk_markets_prices', static function (): array {
            $market = isset($_POST['market']) ? sanitize_key(wp_unslash((string) $_POST['market'])) : '';

            if (!in_array($market, self::markets(), true)) {
                throw new ApiException('kk_invalid_market', __('Unknown market.', 'koperasikita'), 400);
            }

            $prices = [];
            foreach (self::prices($market) as $symbol => $price) {
                $prices[$symbol] = $price->toArray();
            }

            return [
                'market' => $market,
                'provider' => self::providerFor($market)->id(),
                'prices' => $prices,
            ];
        }, true);

        MarketsSettings::register();
    }

    /**
     * Client-side fetch info for a market: the chosen provider id
     * and the public API URL the browser may call directly, or null
     * when the provider is only served through the backend endpoint.
     * The frontend (src/ts/markets.ts) uses this to decide whether
     * to call the public API straight from the browser.
     *
     * @return array{provider: string, clientApiUrl: string|null}
     */
    public static function clientInfo(string $market): array
    {
        $provider = self::providerFor($market);
        $url = $provider instanceof AbstractLiveProvider ? $provider->clientApiUrl() : null;

        return [
            'provider' => $provider->id(),
            'clientApiUrl' => $url,
        ];
    }
}

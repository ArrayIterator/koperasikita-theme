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
use KoperasiKita\Providers\LogamMuliaApiProvider;
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
 * in a template. Gold is the exception: each gold brand has its own
 * provider + fallback, stored in `kk_gold_brand_providers` /
 * `kk_gold_brand_fallback_providers` and edited on the Gold Prices page
 * (see Gold::brandProviders()). When the feature is disabled nothing here hooks in at all:
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
            // IHSG + LQ45 indices first, then the LQ45 constituents:
            // the 12 heaviest constituents by index weight first (so
            // a default display shows the most liquid names), then
            // the rest alphabetically. Constituents per the IDX
            // announcements Peng-00205/BEI.POP/10-2026 (27 Oct 2026)
            // and the 11 Nov 2026 revision (MAPI/NCKL/PGEO out,
            // ERAA/GGRM/PWON in), effective 18 Nov 2026 – 29 Jan 2027.
            // Every code was verified live against Yahoo Finance
            // (see ~/workspace/kk-verify-stocks/live-check.php) — a
            // code that returns no Yahoo data must not be added here.
            // Labels are the tickers themselves: that is what readers
            // expect for stocks, and it matches the IHSG/LQ45 labels.
            $symbols = [
                'ihsg' => 'IHSG',
                'lq45' => 'LQ45',
                'bbca' => 'BBCA',
                'bbri' => 'BBRI',
                'bmri' => 'BMRI',
                'tlkm' => 'TLKM',
                'asii' => 'ASII',
                'ammn' => 'AMMN',
                'bbni' => 'BBNI',
                'icbp' => 'ICBP',
                'untr' => 'UNTR',
                'aadi' => 'AADI',
                'amrt' => 'AMRT',
                'antm' => 'ANTM',
                'admr' => 'ADMR',
                'adro' => 'ADRO',
                'akra' => 'AKRA',
                'bbtn' => 'BBTN',
                'brpt' => 'BRPT',
                'bumi' => 'BUMI',
                'cpin' => 'CPIN',
                'dewa' => 'DEWA',
                'elsa' => 'ELSA',
                'emtk' => 'EMTK',
                'eraa' => 'ERAA',
                'essa' => 'ESSA',
                'excl' => 'EXCL',
                'ggrm' => 'GGRM',
                'goto' => 'GOTO',
                'hrta' => 'HRTA',
                'inco' => 'INCO',
                'indf' => 'INDF',
                'indy' => 'INDY',
                'inkp' => 'INKP',
                'isat' => 'ISAT',
                'itmg' => 'ITMG',
                'jpfa' => 'JPFA',
                'klbf' => 'KLBF',
                'mbma' => 'MBMA',
                'mdka' => 'MDKA',
                'medc' => 'MEDC',
                'pgas' => 'PGAS',
                'ptba' => 'PTBA',
                'pwon' => 'PWON',
                'smgr' => 'SMGR',
                'unvr' => 'UNVR',
                'wifi' => 'WIFI',
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
     * Block-editor preview mode: while true, live providers serve
     * their cached / last-good value and never fetch upstream (see
     * AbstractLiveProvider::prices()). Set only around a block
     * render served over REST (Blocks::render) — the editor canvas
     * and the inserter preview must not stall on an upstream
     * timeout while a cold transient refetches. Frontend page
     * renders are never REST requests, so they still refresh live.
     */
    private static bool $previewMode = false;

    /** @internal Only Blocks::render() sets this, around one render. */
    public static function setPreviewMode(bool $on): void
    {
        self::$previewMode = $on;
    }

    public static function isPreviewMode(): bool
    {
        return self::$previewMode;
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
                // Stock and crypto default to their live providers:
                // Yahoo (IDX) and CoinGecko. Manual only wins for a
                // market where an admin typed a snapshot — see
                // providerFor(). Gold stays manual by default because
                // each brand resolves its own provider (see
                // Gold::brandProviders()).
                'stock' => 'yahoo',
                'crypto' => 'coingecko',
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
     * Symbols shown for a market.
     *
     * Gold is per-brand now (see prices()): every gold symbol has its
     * own provider, so the shown set is the full symbol list — the
     * stored picker (from the removed Settings -> Markets screen) is
     * still honoured when it is non-empty, for backwards compatibility.
     * Other markets still show the current provider's symbols.
     *
     * @return array<string, string> Enabled symbols (slug => label).
     */
    public static function enabledSymbols(string $market): array
    {
        if ($market === 'gold') {
            $symbols = self::symbols('gold');
            $stored = self::settings()['enabled'][$market] ?? null;

            if (!is_array($stored) || $stored === []) {
                return $symbols;
            }

            $enabled = [];

            foreach ($symbols as $symbol => $label) {
                if (!empty($stored[$symbol])) {
                    $enabled[$symbol] = $label;
                }
            }

            // An admin who unchecks everything gets the full list
            // back: an empty picker must not blank the whole market.
            return $enabled !== [] ? $enabled : $symbols;
        }

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

    /**
     * The preferred live provider per market, in order, when the
     * stored choice is Manual with no snapshot behind it (see
     * providerFor()). Yahoo is the only stock provider; CoinGecko
     * is the preferred crypto one — Indodax is deliberately not
     * listed: it timed out from the web process, so it must never
     * become the automatic choice. Gold is per-brand (see
     * Gold::brandProviders()) and has no entry here.
     *
     * @var array<string, array<int, string>>
     */
    private const LIVE_PROVIDER_ORDER = [
        'stock' => ['yahoo'],
        'crypto' => ['coingecko'],
    ];

    /**
     * The provider that supplies a market.
     *
     * An explicit non-manual choice that supports the market wins.
     * Manual only wins when it actually prices something for the
     * market: it supplies every symbol on paper but prices only
     * the ones an admin typed a snapshot for, so a bare 'manual'
     * — the old default, with no snapshots — must not blank the
     * market while a live provider has data. Same carve-out as
     * Gold::brandProviders(). Otherwise the first live provider
     * for the market (LIVE_PROVIDER_ORDER) supplies it; with no
     * live provider available, any registered provider that
     * supports the market, else Manual.
     *
     * Nothing is written on read — the fallback is computed, so a
     * fresh install and an upgraded install behave the same
     * without a data migration.
     */
    public static function providerFor(string $market): MarketPriceProvider
    {
        $providers = self::providers();
        $chosen = self::settings()['providers'][$market] ?? 'manual';

        if ($chosen !== 'manual' && isset($providers[$chosen]) && $providers[$chosen]->supports($market)) {
            return $providers[$chosen];
        }

        if ($chosen === 'manual' && self::manualHasSnapshotPrice($market)) {
            return $providers['manual'];
        }

        foreach (self::LIVE_PROVIDER_ORDER[$market] ?? [] as $live) {
            if (isset($providers[$live]) && $providers[$live]->supports($market)) {
                return $providers[$live];
            }
        }

        foreach ($providers as $provider) {
            if ($provider->supports($market)) {
                return $provider;
            }
        }

        return $providers['manual'];
    }

    /**
     * Whether the admin typed a manual snapshot price for any
     * symbol of the market. A snapshot row only counts with a
     * positive price (the sanitizer drops the rest, see
     * MarketsSettings::sanitizeSettings()).
     */
    private static function manualHasSnapshotPrice(string $market): bool
    {
        $snapshots = self::settings()['snapshots'][$market] ?? [];

        if (!is_array($snapshots)) {
            return false;
        }

        foreach ($snapshots as $row) {
            if (is_array($row) && isset($row['price']) && (float) $row['price'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Choices for a provider select: every registered provider that
     * supports the market, id => label. Manual is always first (it
     * is the fallback wherever an admin typed a snapshot).
     *
     * @return array<string, string>
     */
    public static function providerChoices(string $market): array
    {
        $choices = [];

        foreach (self::providers() as $id => $provider) {
            if ($provider->supports($market)) {
                $choices[$id] = $provider->label();
            }
        }

        if (!isset($choices['manual'])) {
            $choices = ['manual' => __('Manual', 'koperasikita')] + $choices;
        }

        return $choices;
    }

    /**
     * Set the provider for one market (stored in the kk_markets_settings
     * option). An unknown provider, or one that does not support the
     * market, falls back to Manual. Returns the provider actually saved.
     */
    public static function setProvider(string $market, string $provider): string
    {
        $choices = self::providerChoices($market);
        $provider = isset($choices[$provider]) ? $provider : 'manual';

        $settings = self::settings();
        $settings['providers'][$market] = $provider;
        update_option(self::OPTION, $settings);

        return $provider;
    }

    /**
     * Prices for one market, keyed by symbol. Only symbols with a real
     * snapshot price are returned — an empty array means "no data", and the
     * caller renders an honest empty state. Only the symbols the admin
     * picked in the provider picker are returned (see enabledSymbols()).
     *
     * Gold resolves PER BRAND (see goldPrices()): each brand's own
     * provider is tried first, then its fallback provider, and the
     * returned price is annotated with the provider that actually
     * supplied it (MarketPrice::$provider).
     *
     * @return array<string, MarketPrice>
     */
    public static function prices(string $market): array
    {
        if (!self::isEnabled() || !in_array($market, self::markets(), true)) {
            return [];
        }

        if ($market === 'gold') {
            return self::goldPrices();
        }

        $enabled = self::enabledSymbols($market);

        if ($enabled === []) {
            return [];
        }

        $prices = self::providerFor($market)->prices($market);

        return array_intersect_key($prices, $enabled);
    }

    /**
     * In-request cache of one provider's gold prices: a provider is
     * fetched at most once per page render / request, no matter how
     * many brands use it (the transient underneath already caches
     * across requests; this stops repeat fetches inside one request).
     *
     * @var array<string, array<string, MarketPrice>|null>
     */
    private static array $goldProviderPrices = [];

    /** One provider's gold prices, fetched at most once per request. */
    private static function goldProviderPrices(string $providerId): array
    {
        if (!isset(self::$goldProviderPrices[$providerId])) {
            $providers = self::providers();
            $provider = $providers[$providerId] ?? null;
            self::$goldProviderPrices[$providerId] = $provider instanceof MarketPriceProvider && $provider->supports('gold')
                ? $provider->prices('gold')
                : [];
        }

        return self::$goldProviderPrices[$providerId] ?? [];
    }

    /**
     * Gold prices resolved per brand.
     *
     * Each enabled symbol is priced from its own primary provider
     * (Gold::brandProvider); when the primary has no price for the
     * symbol, the symbol's fallback provider (Gold::brandFallbackProvider)
     * is tried. A symbol neither can price is left out — the widget
     * and strip show an honest empty state for it, never an invented
     * price. Every returned price carries the provider that actually
     * supplied it in MarketPrice::$provider.
     *
     * Request count: providers are grouped, so one fetch per provider
     * serves every brand that uses it — the scraped prices.json is
     * read once for all six of its symbols, the Logam Mulia table is
     * read once for all five of its brands.
     *
     * @return array<string, MarketPrice>
     */
    public static function goldPrices(): array
    {
        $enabled = self::enabledSymbols('gold');

        if ($enabled === []) {
            return [];
        }

        $prices = [];

        foreach ($enabled as $symbol => $label) {
            $primary = Gold::brandProvider($symbol);
            $fromPrimary = self::goldProviderPrices($primary);
            $price = $fromPrimary[$symbol] ?? null;

            if ($price instanceof MarketPrice && $price->hasData()) {
                $prices[$symbol] = $price->withProvider($primary);
                continue;
            }

            $fallback = Gold::brandFallbackProvider($symbol);

            if ($fallback === '' || $fallback === $primary) {
                continue;
            }

            $fromFallback = self::goldProviderPrices($fallback);
            $fallbackPrice = $fromFallback[$symbol] ?? null;

            if ($fallbackPrice instanceof MarketPrice && $fallbackPrice->hasData()) {
                $prices[$symbol] = $fallbackPrice->withProvider($fallback);
            }
        }

        return $prices;
    }

    /**
     * Markets for the homepage strip, in the same shape the widget passes
     * to template-parts/markets/strip.php. An empty array means "do not
     * render the strip at all": the Markets feature is disabled. When the
     * strip does render, the template itself shows an honest empty state
     * if no market has data yet — the same as the widget. The strip is
     * always on (there is no Customizer toggle for it).
     *
     * @return array<string, array{label: string, prices: array<string, MarketPrice>}>
     */
    public static function homepageStripMarkets(): array
    {
        if (!self::isEnabled()) {
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
        // next to Manual. Stock defaults to Yahoo and crypto to
        // CoinGecko (see settings()); Manual wins only where an
        // admin typed a snapshot (see providerFor()). Each
        // provider caches in a transient (15 min) and falls back
        // to its last good value when the upstream is unreachable.
        add_filter(Hooks::MARKETS_PROVIDERS, static function (array $providers): array {
            $providers['xaus'] = new XausProvider();
            $providers['scraped-gold'] = new ScrapedGoldProvider();
            $providers['logam-mulia-api'] = new LogamMuliaApiProvider();
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
                // Gold is per-brand: no single provider supplies it.
                'provider' => $market === 'gold' ? 'per-brand' : self::providerFor($market)->id(),
                'prices' => $prices,
            ];
        }, true);

        // Logam Mulia daily sync (WP-Cron) + the manual sync AJAX
        // endpoint (one source per call). Only when the Markets
        // feature is enabled; gold brands prefer this provider by
        // default (see Gold::brandProviders()).
        LogamMuliaApiProvider::registerSync();

        MarketsSettings::register();
    }

    /**
     * Client-side fetch info for a market: the chosen provider id
     * and the public API URL the browser may call directly, or null
     * when the provider is only served through the backend endpoint.
     * The frontend (src/ts/markets.ts) uses this to decide whether
     * to call the public API straight from the browser.
     *
     * Gold is per-brand: the direct client API is the one provider
     * with a browser-callable URL among the brands' effective
     * providers — Scraped (prices.json, covers six symbols) first,
     * then Xaus (spot only). The browser refresh only updates the
     * symbols that provider covers; the rest stay server-rendered
     * and refresh through the backend endpoint.
     *
     * @return array{provider: string, clientApiUrl: string|null}
     */
    public static function clientInfo(string $market): array
    {
        if ($market === 'gold') {
            $inUse = [];

            foreach (array_keys(self::enabledSymbols('gold')) as $symbol) {
                $inUse[Gold::brandProvider($symbol)] = true;
                $fallback = Gold::brandFallbackProvider($symbol);

                if ($fallback !== '') {
                    $inUse[$fallback] = true;
                }
            }

            foreach (['scraped-gold', 'xaus'] as $candidate) {
                if (!isset($inUse[$candidate])) {
                    continue;
                }

                $provider = self::providers()[$candidate] ?? null;

                if ($provider instanceof AbstractLiveProvider && $provider->clientApiUrl() !== null) {
                    return [
                        'provider' => $provider->id(),
                        'clientApiUrl' => $provider->clientApiUrl(),
                    ];
                }
            }

            return ['provider' => 'manual', 'clientApiUrl' => null];
        }

        $provider = self::providerFor($market);
        $url = $provider instanceof AbstractLiveProvider ? $provider->clientApiUrl() : null;

        return [
            'provider' => $provider->id(),
            'clientApiUrl' => $url,
        ];
    }
}

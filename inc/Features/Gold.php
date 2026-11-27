<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Hooks;
use KoperasiKita\Providers\GoldPricesTable;
use KoperasiKita\Providers\LogamMuliaApiProvider;
use KoperasiKita\Providers\MarketPrice;

/**
 * Gold brands for the gold price widget.
 *
 * The brands are the registry; their prices come from the Markets
 * snapshot layer (Settings -> Markets), never from hardcoded values here.
 * A brand with no saved snapshot price is simply left out of the slider,
 * and a slider with no priced brands at all renders an honest empty state.
 *
 * Live prices come from the scraper for Antam, UBS, Galeri 24, Lotus
 * Archi, King Halim and Emasku (HRTA GOLD) (+ spot). Mini gold
 * (MiniGold, EOA Gold, IGS Gold, Waris Sampoerna) is a separate
 * category - small denominations, not gram bars - so it is not in
 * this registry.
 *
 * Third parties may add or rename brands through the
 * `koperasikita/gold/brands` filter. A brand added there with no
 * snapshot still has no price.
 */
final class Gold
{
    /** Option holding the enabled gold brand slugs (array of strings). */
    public const ENABLED_OPTION = 'kk_enabled_gold_brands';

    /** Option holding the provider per gold symbol (symbol => provider id). */
    public const PROVIDERS_OPTION = 'kk_gold_brand_providers';

    /** Option holding the fallback provider per gold symbol (symbol => provider id or ''). */
    public const FALLBACKS_OPTION = 'kk_gold_brand_fallback_providers';

    /**
     * Live providers in the order a never-configured brand prefers
     * them. Logam Mulia API first: it reads the synced table (a
     * local database read - fast, and the prices the site's own
     * sync pipeline curates). Scraped second: the 15-minute scrape,
     * a network fetch, so it stays the fallback rather than the hot
     * path. Xaus last: spot only. Manual is not in this list - it
     * supplies every symbol on paper but only prices the ones an
     * admin typed a snapshot for, so preferring it first would
     * blank the widget on any site that never typed one.
     *
     * @var array<int, string>
     */
    private const LIVE_PROVIDER_ORDER = ['logam-mulia-api', 'scraped-gold', 'xaus'];

    /** @return array<string, string> Brand slug => display name. */
    public static function brands(): array
    {
        $brands = apply_filters(Hooks::GOLD_BRANDS, [
            'antam' => 'Antam',
            'ubs' => 'UBS',
            'galeri-24' => 'Galeri 24',
            'lotus-archi' => 'Lotus Archi',
            'king-halim' => 'King Halim',
            'hartadinata' => 'Emasku (HRTA GOLD)',
        ]);

        if (!is_array($brands)) {
            return [];
        }

        $clean = [];

        foreach ($brands as $slug => $label) {
            if (is_string($slug) && $slug !== '' && is_string($label)) {
                $clean[$slug] = $label;
            }
        }

        return $clean;
    }

    /**
     * One-line descriptions per brand, shown under the brand name
     * on the Gold Prices admin page (Prices tab). Only the five
     * brands the user described have one - Emasku (HRTA GOLD) has
     * none, and a brand without one renders nothing.
     *
     * @return array<string, string> Brand slug => description.
     */
    public static function brandDescriptions(): array
    {
        return [
            'antam' => __('Produced by a state-owned enterprise with integrated certificate packaging (CertiEye), generally available from 0.5 grams to 1 kg, and known for its wide liquidity.', 'koperasikita'),
            'ubs' => __('Founded in 1981, offering a wide choice of sizes and designs (including themed series such as Disney/Hello Kitty) at prices often more affordable than Antam.', 'koperasikita'),
            'galeri-24' => __('Gold products from a subsidiary of PT Pegadaian, meeting SNI standards, available as bars and jewelry, and easily accessible through the Pegadaian network and online or offline channels. Galeri 24 also reviews this range of gold bar brands.', 'koperasikita'),
            'lotus-archi' => __('Produced since 1975 and a member of the gold/jewelry industry association, available in a wide choice of weights and classic or themed designs.', 'koperasikita'),
            'king-halim' => __('A local gold producer offering gold bars and jewelry in a wide choice of weight sizes.', 'koperasikita'),
        ];
    }

    /** The description of one brand ('' when it has none). */
    public static function brandDescription(string $slug): string
    {
        return self::brandDescriptions()[sanitize_key($slug)] ?? '';
    }

    /**
     * The enabled gold brand slugs, in registry order.
     *
     * Managed on the Gold Prices admin page ("Enabled Gold Brands"
     * tab). When the option has never been saved, every brand in
     * the registry is enabled - the tab is an opt-out, not an
     * opt-in, so existing sites keep showing all their brands.
     * A saved empty list disables every brand on purpose.
     *
     * @return array<int, string>
     */
    public static function enabledBrands(): array
    {
        $all = array_keys(self::brands());
        $stored = get_option(self::ENABLED_OPTION, false);

        if ($stored === false) {
            return $all;
        }

        if (!is_array($stored)) {
            return $all;
        }

        return array_values(array_intersect($stored, $all));
    }

    /**
     * Save the enabled gold brand slugs (unknown slugs are
     * dropped, so a stale saved list never enables a brand that
     * no longer exists).
     *
     * @param array<int, string> $slugs
     */
    public static function setEnabledBrands(array $slugs): void
    {
        $clean = array_values(array_intersect($slugs, array_keys(self::brands())));
        update_option(self::ENABLED_OPTION, $clean);
    }

    public static function isEnabled(): bool
    {
        return Markets::isEnabled();
    }

    /**
     * Every gold symbol the site can price: the spot row first, then
     * the brands in registry order. The Gold Prices page lists exactly
     * these rows, one provider choice per row.
     *
     * @return array<string, string> Symbol slug => display name.
     */
    public static function goldSymbols(): array
    {
        return array_merge(['spot' => __('Gold spot', 'koperasikita')], self::brands());
    }

    /**
     * Providers that can actually supply one gold symbol, id => label,
     * derived from each provider's own supplies() - never a hardcoded
     * list in the template. Manual can supply everything; Xaus only
     * spot; Scraped covers spot + all six brands; Logam Mulia API
     * covers four brands (Antam, UBS, Galeri 24, Lotus Archi), no
     * spot. Every brand has a live source, so Manual is never the
     * only choice.
     *
     * @return array<string, string>
     */
    public static function providerChoicesForBrand(string $symbol): array
    {
        $symbol = sanitize_key($symbol);
        $choices = [];

        foreach (Markets::providers() as $id => $provider) {
            if (!$provider->supports('gold')) {
                continue;
            }

            $supplies = $provider->supplies('gold');

            if (isset($supplies[$symbol])) {
                $choices[$id] = $provider->label();
            }
        }

        // Manual is always available (it supplies every symbol), so a
        // brand with no live source still has exactly one choice.
        if (!isset($choices['manual'])) {
            $choices = ['manual' => __('Manual', 'koperasikita')] + $choices;
        }

        return $choices;
    }

    /**
     * Fallback choices for one brand: the providers that can supply
     * it, minus the primary, plus "None". The fallback is only tried
     * when the primary returns no price for the brand.
     *
     * @return array<string, string> Provider id ('' = none) => label.
     */
    public static function fallbackChoicesForBrand(string $symbol, string $primary): array
    {
        $choices = ['' => __('None', 'koperasikita')];

        foreach (self::providerChoicesForBrand($symbol) as $id => $label) {
            if ($id !== $primary) {
                $choices[$id] = $label;
            }
        }

        return $choices;
    }

    /** The display label of one provider id (the id itself when unknown). */
    public static function providerLabel(string $providerId): string
    {
        $providers = Markets::providers();

        return isset($providers[$providerId]) ? $providers[$providerId]->label() : $providerId;
    }

    /**
     * The provider per gold symbol (symbol => provider id).
     *
     * The admin picks one per brand on the Gold Prices page; a symbol
     * with no saved choice migrates from the old single global gold
     * provider (kk_markets_settings providers.gold) when that provider
     * can supply it - except a bare 'manual' legacy, which only counts
     * when a manual snapshot price actually exists for the symbol.
     * Otherwise the symbol prefers a live provider (see
     * LIVE_PROVIDER_ORDER) and only falls back to Manual when no live
     * provider can supply it. Nothing is written on read - the
     * migration is computed, so a fresh install and an upgraded
     * install behave the same without a data migration.
     *
     * The 'manual' carve-out matters: Manual supplies every symbol
     * on paper but prices only the ones an admin typed a snapshot
     * for, so the old default ('manual' with no snapshots) blanked
     * the gold widget and the block inserter previews even while
     * the synced Logam Mulia table held prices for five brands.
     *
     * @return array<string, string>
     */
    public static function brandProviders(): array
    {
        $symbols = array_keys(self::goldSymbols());
        $stored = get_option(self::PROVIDERS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $legacy = Markets::settings()['providers']['gold'] ?? 'manual';
        $snapshots = Markets::settings()['snapshots']['gold'] ?? [];
        $snapshots = is_array($snapshots) ? $snapshots : [];

        $map = [];

        foreach ($symbols as $symbol) {
            $chosen = isset($stored[$symbol]) ? sanitize_key((string) $stored[$symbol]) : '';
            $choices = self::providerChoicesForBrand($symbol);

            if ($chosen !== '' && isset($choices[$chosen])) {
                $map[$symbol] = $chosen;
                continue;
            }

            // The old single global provider, when it can supply the
            // symbol - but a bare 'manual' legacy only counts when a
            // snapshot price exists for this symbol. Manual with no
            // snapshot prices nothing, so it must not win over a live
            // provider that actually has data.
            $manualHasPrice = isset($snapshots[$symbol]['price']) && (float) $snapshots[$symbol]['price'] > 0;

            if (isset($choices[$legacy]) && ($legacy !== 'manual' || $manualHasPrice)) {
                $map[$symbol] = $legacy;
                continue;
            }

            // No saved choice and no live legacy: prefer a live
            // provider that can supply the symbol, in live order.
            foreach (self::LIVE_PROVIDER_ORDER as $live) {
                if (isset($choices[$live])) {
                    $map[$symbol] = $live;
                    continue 2;
                }
            }

            $map[$symbol] = 'manual';
        }

        return $map;
    }

    /** The provider supplying one gold symbol (primary choice). */
    public static function brandProvider(string $symbol): string
    {
        $map = self::brandProviders();

        return $map[sanitize_key($symbol)] ?? 'manual';
    }

    /**
     * The official provider per brand: the brand's configured
     * primary provider. That choice is where "official" is
     * decided (Gold Brands tab): the official site's feed for a
     * brand is the provider the admin picked for it. A brand
     * whose primary is the Logam Mulia CSV has no official-site
     * feed; its history stays the CSV rows.
     *
     * @return array<string, string> Brand slug => provider id.
     */
    public static function officialProviders(): array
    {
        return array_intersect_key(self::brandProviders(), self::brands());
    }

    /**
     * Record today's official price per brand in the history
     * table. The price is the one the site itself shows
     * (Markets::goldPrices(), primary then fallback), so history
     * and the widget can never disagree. Only a price supplied
     * by the brand's official provider is written: a price that
     * arrived via the fallback is the fallback's own row to
     * maintain, and a brand whose primary is the Logam Mulia
     * CSV is skipped outright, its history already written by
     * the CSV sync. A failed or missing fetch writes nothing.
     * The write is an upsert, so a re-run the same day updates
     * today's official row instead of duplicating it.
     *
     * @return array{inserted: int, updated: int, skipped: int, brands: array<string, array{provider: string, outcome: string}>}
     */
    public static function recordOfficialHistory(): array
    {
        $today = date_i18n('Y-m-d', current_time('timestamp'));
        $prices = Markets::goldPrices();
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'brands' => []];

        foreach (self::enabledBrands() as $symbol) {
            $official = self::brandProvider($symbol);

            if ($official === LogamMuliaApiProvider::PROVIDER_ID) {
                continue;
            }

            $price = $prices[$symbol] ?? null;
            $outcome = 'skipped';

            if ($price instanceof MarketPrice && $price->hasData() && $price->provider === $official) {
                $written = GoldPricesTable::upsert($official, $symbol, (float) $price->price, null, 'IDR', $today, time());
                $outcome = $written === 1 ? 'inserted' : ($written === 2 ? 'updated' : 'unchanged');
            }

            if ($outcome === 'unchanged' || $outcome === 'skipped') {
                $result['skipped']++;
            } else {
                $result[$outcome]++;
            }

            $result['brands'][$symbol] = ['provider' => $official, 'outcome' => $outcome];
        }

        return $result;
    }

    /**
     * The fallback provider per gold symbol (symbol => provider id,
     * '' = none). A saved fallback that cannot supply the symbol, or
     * that equals the primary, is dropped on read. A symbol with no
     * saved fallback defaults to the next live provider that can
     * supply it (same live order as the primary), so a brand whose
     * primary has no price still resolves instead of rendering
     * empty; brands only Manual can supply keep no fallback.
     *
     * @return array<string, string>
     */
    public static function brandFallbackProviders(): array
    {
        $stored = get_option(self::FALLBACKS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $primaries = self::brandProviders();

        $map = [];

        foreach (array_keys(self::goldSymbols()) as $symbol) {
            $fallback = isset($stored[$symbol]) ? sanitize_key((string) $stored[$symbol]) : '';
            $choices = self::fallbackChoicesForBrand($symbol, $primaries[$symbol] ?? 'manual');

            if ($fallback !== '' && isset($choices[$fallback])) {
                $map[$symbol] = $fallback;
                continue;
            }

            $map[$symbol] = '';

            foreach (self::LIVE_PROVIDER_ORDER as $live) {
                if ($live !== ($primaries[$symbol] ?? 'manual') && isset($choices[$live])) {
                    $map[$symbol] = $live;
                    break;
                }
            }
        }

        return $map;
    }

    /** The fallback provider for one gold symbol ('' = none). */
    public static function brandFallbackProvider(string $symbol): string
    {
        $map = self::brandFallbackProviders();

        return $map[sanitize_key($symbol)] ?? '';
    }

    /**
     * Save the per-brand provider + fallback maps. Unknown symbols
     * are dropped, a provider that cannot supply its brand falls back
     * to Manual, and a fallback that cannot supply the brand - or
     * that equals the primary - becomes "none".
     *
     * @param array<string, string> $providers Symbol => provider id.
     * @param array<string, string> $fallbacks Symbol => provider id or ''.
     */
    public static function setBrandProviders(array $providers, array $fallbacks): void
    {
        $cleanProviders = [];
        $cleanFallbacks = [];

        foreach (array_keys(self::goldSymbols()) as $symbol) {
            $choices = self::providerChoicesForBrand($symbol);
            $chosen = isset($providers[$symbol]) ? sanitize_key((string) $providers[$symbol]) : '';
            $cleanProviders[$symbol] = isset($choices[$chosen]) ? $chosen : 'manual';

            $fallbackChoices = self::fallbackChoicesForBrand($symbol, $cleanProviders[$symbol]);
            $fallback = isset($fallbacks[$symbol]) ? sanitize_key((string) $fallbacks[$symbol]) : '';
            $cleanFallbacks[$symbol] = isset($fallbackChoices[$fallback]) && $fallback !== '' ? $fallback : '';
        }

        update_option(self::PROVIDERS_OPTION, $cleanProviders);
        update_option(self::FALLBACKS_OPTION, $cleanFallbacks);
    }

    /**
     * Priced brands only, in registry order.
     *
     * @param array<int, string> $only When non-empty, limit to these slugs.
     * @return array<string, MarketPrice> Brand slug => snapshot price.
     */
    public static function brandPrices(array $only = []): array
    {
        $prices = Markets::prices('gold');
        $brands = [];

        foreach (self::brands() as $slug => $label) {
            if ($only !== [] && !in_array($slug, $only, true)) {
                continue;
            }

            if (isset($prices[$slug]) && $prices[$slug]->hasData()) {
                $brands[$slug] = $prices[$slug];
            }
        }

        return $brands;
    }

    public static function spotPrice(): ?MarketPrice
    {
        $prices = Markets::prices('gold');
        $spot = $prices['spot'] ?? null;

        return $spot !== null && $spot->hasData() ? $spot : null;
    }

    /**
     * Brand logo URLs (slug => theme asset URL), for the Gold widget.
     *
     * Antam, UBS and Galeri 24 have no official logo on Commons /
     * Wikipedia, so they use a generic initials mark drawn for the
     * theme (assets/img/brands/*.svg) - never a fabricated official
     * logo. A brand with no file returns ''.
     *
     * @return array<string, string>
     */
    public static function logos(): array
    {
        $logos = [];

        foreach (self::brands() as $slug => $label) {
            $logos[$slug] = self::logo($slug);
        }

        return $logos;
    }

    public static function logo(string $slug): string
    {
        $slug = sanitize_key($slug);
        $relative = 'assets/img/brands/' . $slug . '.svg';

        if (!is_readable(get_theme_file_path($relative))) {
            return '';
        }

        $url = get_theme_file_uri($relative);

        return is_string($url) ? $url : '';
    }
}

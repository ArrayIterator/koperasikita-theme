<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Hooks;
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
 * Archi and Pegadaian (+ spot). Hartadinata, King Halim and
 * EmasKita have no public live price source, so they show only
 * when a manual snapshot price exists. Mini gold (MiniGold, EOA
 * Gold, IGS Gold, Waris Sampoerna) is a separate category — small
 * denominations, not gram bars — so it is not in this registry.
 *
 * Third parties may add or rename brands through the
 * `koperasikita/gold/brands` filter. A brand added there with no
 * snapshot still has no price.
 */
final class Gold
{
    /** Option holding the enabled gold brand slugs (array of strings). */
    public const ENABLED_OPTION = 'kk_enabled_gold_brands';

    /** @return array<string, string> Brand slug => display name. */
    public static function brands(): array
    {
        $brands = apply_filters(Hooks::GOLD_BRANDS, [
            'antam' => 'Antam',
            'ubs' => 'UBS',
            'galeri-24' => 'Galeri 24',
            'lotus-archi' => 'Lotus Archi',
            'pegadaian' => 'Pegadaian',
            'hartadinata' => 'Hartadinata',
            'king-halim' => 'King Halim',
            'emaskita' => 'EmasKita',
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
     * The enabled gold brand slugs, in registry order.
     *
     * Managed on the Gold Prices admin page ("Enabled Gold Brands"
     * tab). When the option has never been saved, every brand in
     * the registry is enabled — the tab is an opt-out, not an
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
     * Pegadaian uses its official logo from Wikimedia Commons
     * (https://commons.wikimedia.org/wiki/File:Pegadaian_logo_(2013).svg,
     * stored at assets/img/brands/pegadaian.svg). Antam, UBS and
     * Galeri 24 have no official logo on Commons / Wikipedia, so they
     * use a generic initials mark drawn for the theme (assets/img/brands/*.svg)
     * — never a fabricated official logo. EmasKita is KK-specific and
     * uses an initials mark too. A brand with no file returns ''.
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

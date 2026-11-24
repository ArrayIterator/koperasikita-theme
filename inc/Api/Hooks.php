<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

/**
 * The theme's public hook names — the single source of truth.
 *
 * Every hook a third party can use starts with `koperasikita/`. The
 * constants below are the canonical names; features apply the filters
 * through these constants so a rename in one place renames everywhere.
 * The full list, with parameters and return shapes, is documented in
 * docs/API.md.
 *
 * Filters only — the theme exposes no custom actions in v1. A filter
 * that receives something invalid (not an array, a provider that does
 * not implement its contract) is ignored defensively by the caller,
 * so a broken third-party callback can never take the site down.
 */
final class Hooks
{
    /**
     * Market price providers, keyed by provider id.
     *
     * Filter: `koperasikita/markets/providers`
     * Receives: `array<string, MarketPriceProvider>` (default: `['manual' => ManualSnapshotProvider]`).
     * Returns: the same shape; entries that are not a MarketPriceProvider are dropped.
     */
    public const MARKETS_PROVIDERS = 'koperasikita/markets/providers';

    /**
     * Market labels, keyed by market slug.
     *
     * Filter: `koperasikita/markets/labels`
     * Receives: `array<string, string>` (default: gold/stock/crypto labels).
     * Returns: the same shape; non-string values are dropped.
     */
    public const MARKETS_LABELS = 'koperasikita/markets/labels';

    /**
     * Known symbols for one market, slug => label.
     *
     * Filter: `koperasikita/markets/symbols`
     * Receives: `array<string, string>` for the market, plus the market
     * slug as the second argument.
     * Returns: the same shape; non-string values are dropped.
     */
    public const MARKETS_SYMBOLS = 'koperasikita/markets/symbols';

    /**
     * Weather providers, keyed by provider id.
     *
     * Filter: `koperasikita/weather/providers`
     * Receives: `array<string, WeatherProvider>` (default: `['open-meteo' => OpenMeteoProvider]`).
     * Returns: the same shape; entries that are not a WeatherProvider are dropped.
     * The theme uses the `open-meteo` provider when present, otherwise
     * the first registered provider.
     */
    public const WEATHER_PROVIDERS = 'koperasikita/weather/providers';

    /**
     * Quick-pick cities shown in the location picker strip.
     *
     * Filter: `koperasikita/weather/quick_cities`
     * Receives: `array<int, array{name: string, latitude: float, longitude: float}>`.
     * Returns: the same shape; entries without a name and finite
     * coordinates inside the valid ranges are dropped.
     */
    public const WEATHER_QUICK_CITIES = 'koperasikita/weather/quick_cities';

    /**
     * Gold brand registry, slug => display name.
     *
     * Filter: `koperasikita/gold/brands`
     * Receives: `array<string, string>` (default: the five brands).
     * Returns: the same shape; non-string values are dropped. Prices
     * still come from the Markets snapshots — a brand added here with
     * no snapshot simply has no price.
     */
    public const GOLD_BRANDS = 'koperasikita/gold/brands';

    /**
     * The Google Fonts catalogue, keyed by role then font id.
     *
     * Filter: `koperasikita/fonts/catalogue`
     * Receives: the full catalogue array (see Fonts::catalogue()).
     * Returns: the same shape; entries missing id/label/type/stack,
     * or Google entries missing family/weights, are dropped.
     */
    public const FONTS_CATALOGUE = 'koperasikita/fonts/catalogue';
}

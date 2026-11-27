<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Contract for anything that can supply prices for a market.
 *
 * Third-party providers plug in through the `koperasikita/markets/providers`
 * filter, which receives an array of MarketPriceProvider keyed by provider id.
 * The Manual snapshot provider is the built-in default for every market.
 *
 * Rules every implementation must follow:
 *  - `prices()` returns only symbols with a real price. An empty array
 *    means "no data" - never return a zero or invented price.
 *  - `supports()` answers whether the provider can serve the market at
 *    all, before any network or storage is touched.
 *  - `supplies()` lists the symbols the provider CAN supply for a
 *    market (slug => label). The Settings -> Markets picker popup
 *    shows exactly this list, so the admin only ever picks from
 *    symbols the provider really has. A provider that can supply
 *    everything for a market returns the market's full symbol list.
 *  - A provider must never fatal on bad input; an unreachable upstream
 *    answers with an empty array, not an exception.
 */
interface MarketPriceProvider
{
    public function id(): string;

    public function label(): string;

    public function supports(string $market): bool;

    /** @return array<string, string> Symbols this provider can supply (slug => label). */
    public function supplies(string $market): array;

    /** @return array<string, MarketPrice> Prices keyed by symbol. */
    public function prices(string $market): array;
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Manual snapshot provider: prices typed in by an admin on the
 * Settings -> Markets screen and stored in the `kk_markets_settings` option.
 *
 * This is the v1 default for gold, stock and crypto. A market with no saved
 * snapshot returns no prices at all, so the frontend shows an honest
 * "no data" state instead of inventing numbers.
 */
final class ManualSnapshotProvider implements MarketPriceProvider
{
    public function id(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual snapshot';
    }

    public function supports(string $market): bool
    {
        return in_array($market, Markets::markets(), true);
    }

    public function prices(string $market): array
    {
        if (!$this->supports($market)) {
            return [];
        }

        $snapshots = Markets::settings()['snapshots'][$market] ?? [];
        $prices = [];

        foreach (Markets::symbols($market) as $symbol => $label) {
            $entry = is_array($snapshots[$symbol] ?? null) ? $snapshots[$symbol] : null;
            $price = $entry !== null && isset($entry['price']) ? (float) $entry['price'] : null;
            $change = $entry !== null && isset($entry['change']) ? (float) $entry['change'] : null;
            $updated = $entry !== null && isset($entry['updated']) ? (int) $entry['updated'] : 0;

            // A snapshot row only counts as data when it carries a price.
            // A saved change percent on its own is not a price.
            if ($price === null) {
                continue;
            }

            $prices[$symbol] = new MarketPrice(
                $market,
                $symbol,
                $label,
                $price,
                $change,
                Markets::currency($market),
                $updated
            );
        }

        return $prices;
    }
}

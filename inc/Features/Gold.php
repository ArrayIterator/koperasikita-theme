<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Gold brands for the gold price widget.
 *
 * The five brands are the registry; their prices come from the Markets
 * snapshot layer (Settings -> Markets), never from hardcoded values here.
 * A brand with no saved snapshot price is simply left out of the slider,
 * and a slider with no priced brands at all renders an honest empty state.
 */
final class Gold
{
    /** @return array<string, string> Brand slug => display name. */
    public static function brands(): array
    {
        return [
            'antam' => 'Antam',
            'ubs' => 'UBS',
            'galeri-24' => 'Galeri 24',
            'pegadaian' => 'Pegadaian',
            'emaskita' => 'EmasKita',
        ];
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
}

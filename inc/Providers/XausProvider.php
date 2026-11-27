<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live gold spot provider (Xaus, free, no API key).
 *
 * Fetches the gold spot price per gram in IDR from xaus.com
 * (https://xaus.com/api/v1/spot?currency=IDR&unit=gram), which
 * returns the price directly in the currency and unit the site
 * needs - no FX conversion, no key, no registration.
 *
 * Caching and the stale-value fallback live in AbstractLiveProvider
 * (15-minute transient + last good value in an option).
 *
 * This provider only supplies the `spot` symbol. The six retail
 * brands are supplied by the other live providers (or Manual):
 * a brand's sell price is not the spot price, so Xaus can never
 * supply one. Set the gold provider back to Manual in Settings
 * -> Markets to return to fully manual prices.
 */
final class XausProvider extends AbstractLiveProvider
{
    public function id(): string
    {
        return 'xaus';
    }

    public function label(): string
    {
        return 'Xaus (live spot, free)';
    }

    public function supports(string $market): bool
    {
        return $market === 'gold';
    }

    public function supplies(string $market): array
    {
        if ($market !== 'gold') {
            return [];
        }

        $symbols = Markets::symbols('gold');

        return isset($symbols['spot']) ? ['spot' => $symbols['spot']] : [];
    }

    protected function transientKey(): string
    {
        return 'kk_gold_spot_xaus';
    }

    protected function lastGoodOption(): string
    {
        return 'kk_gold_spot_xaus_last_good';
    }

    public function clientApiUrl(): ?string
    {
        return 'https://xaus.com/api/v1/spot?currency=IDR&unit=gram';
    }

    /**
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $data = $this->getJson('https://xaus.com/api/v1/spot?currency=IDR&unit=gram');

        if ($data === null) {
            return null;
        }

        $price = $data['xau']['price'] ?? null;

        if (!is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        return [
            'spot' => [
                'price' => (float) $price,
                'change' => null,
                'updated' => time(),
            ],
        ];
    }
}

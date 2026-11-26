<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live stock provider (Yahoo Finance, free, no API key).
 *
 * Fetches the IHSG (^JKSE) index value from Yahoo Finance's public
 * chart endpoint. Unofficial and undocumented — Yahoo can change or
 * block it at any time — so a failed fetch falls back to the cached
 * last good value (AbstractLiveProvider) and then to an honest
 * empty state, never an invented number.
 *
 * Only IHSG is supplied: LQ45 has no Yahoo Finance symbol, so it
 * stays manual. Picking this provider for stocks means IHSG comes
 * live from Yahoo and LQ45 shows no data unless a manual snapshot
 * exists — the admin can switch back to Manual in Settings ->
 * Markets to get both from snapshots.
 *
 * Caching and the stale-value fallback live in AbstractLiveProvider
 * (15-minute transient + last good value in an option).
 */
final class YahooStockProvider extends AbstractLiveProvider
{
    public function id(): string
    {
        return 'yahoo';
    }

    public function label(): string
    {
        return 'Yahoo Finance (live IHSG, free)';
    }

    public function supports(string $market): bool
    {
        return $market === 'stock';
    }

    public function supplies(string $market): array
    {
        if ($market !== 'stock') {
            return [];
        }

        // Only IHSG: LQ45 has no Yahoo Finance symbol, so it never
        // appears in this list and stays manual-only.
        $symbols = Markets::symbols('stock');

        return isset($symbols['ihsg']) ? ['ihsg' => $symbols['ihsg']] : [];
    }

    protected function transientKey(): string
    {
        return 'kk_stock_yahoo';
    }

    protected function lastGoodOption(): string
    {
        return 'kk_stock_yahoo_last_good';
    }

    /**
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $data = $this->getJson('https://query1.finance.yahoo.com/v8/finance/chart/%5EJKSE?interval=1d&range=1d');

        if ($data === null) {
            return null;
        }

        $meta = $data['chart']['result'][0]['meta'] ?? null;

        if (!is_array($meta)) {
            return null;
        }

        $price = $meta['regularMarketPrice'] ?? null;

        if (!is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        $change = $meta['regularMarketChangePercent'] ?? null;

        return [
            'ihsg' => [
                'price' => (float) $price,
                'change' => is_numeric($change) ? (float) $change : null,
                'updated' => time(),
            ],
        ];
    }
}

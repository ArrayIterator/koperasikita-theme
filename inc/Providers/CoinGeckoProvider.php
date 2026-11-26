<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live crypto provider (CoinGecko, free, no API key).
 *
 * One request fetches all five coins in IDR, with the 24-hour
 * change, from the public simple-price endpoint:
 * https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,
 * ethereum,solana,tether,ripple&vs_currencies=idr&
 * include_24hr_change=true
 *
 * CoinGecko's free public API is rate-limited per IP (tens of
 * calls a minute). The 15-minute transient in AbstractLiveProvider
 * keeps the site far below that, and the last-good fallback covers
 * a failed fetch. No key is ever needed.
 *
 * Prices are the global market price in IDR, not any single
 * exchange's price. Indodax is the local-exchange alternative.
 */
final class CoinGeckoProvider extends AbstractLiveProvider
{
    /** CoinGecko ids for the theme's crypto symbols. */
    private const IDS = [
        'btc' => 'bitcoin',
        'eth' => 'ethereum',
        'sol' => 'solana',
        'usdt' => 'tether',
        'xrp' => 'ripple',
    ];

    public function id(): string
    {
        return 'coingecko';
    }

    public function label(): string
    {
        return 'CoinGecko (live crypto, free)';
    }

    public function supports(string $market): bool
    {
        return $market === 'crypto';
    }

    public function supplies(string $market): array
    {
        if ($market !== 'crypto') {
            return [];
        }

        $symbols = Markets::symbols('crypto');
        $supplied = [];

        foreach (array_keys(self::IDS) as $symbol) {
            if (isset($symbols[$symbol])) {
                $supplied[$symbol] = $symbols[$symbol];
            }
        }

        return $supplied;
    }

    protected function transientKey(): string
    {
        return 'kk_crypto_coingecko';
    }

    protected function lastGoodOption(): string
    {
        return 'kk_crypto_coingecko_last_good';
    }

    public function clientApiUrl(): ?string
    {
        return 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,solana,tether,ripple&vs_currencies=idr&include_24hr_change=true';
    }

    /**
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $ids = implode(',', array_values(self::IDS));
        $data = $this->getJson(
            'https://api.coingecko.com/api/v3/simple/price?ids=' . $ids
            . '&vs_currencies=idr&include_24hr_change=true'
        );

        if ($data === null) {
            return null;
        }

        $now = time();
        $prices = [];

        foreach (self::IDS as $symbol => $coinId) {
            $row = $data[$coinId] ?? null;

            if (!is_array($row)) {
                continue;
            }

            $price = $row['idr'] ?? null;

            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }

            $change = $row['idr_24h_change'] ?? null;

            $prices[$symbol] = [
                'price' => (float) $price,
                'change' => is_numeric($change) ? (float) $change : null,
                'updated' => $now,
            ];
        }

        return $prices !== [] ? $prices : null;
    }
}

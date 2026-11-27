<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live crypto provider (Indodax, free, no API key).
 *
 * Fetches the last price in IDR for each coin from Indodax's public
 * ticker endpoint (https://indodax.com/api/ticker/{pair}idr), one
 * request per coin. This is the local Indonesian exchange price -
 * the price an Indonesian buyer actually sees - as opposed to
 * CoinGecko's global market price.
 *
 * No 24-hour change is returned by the ticker, so change is null.
 * Caching and the stale-value fallback live in AbstractLiveProvider
 * (15-minute transient + last good value in an option).
 */
final class IndodaxProvider extends AbstractLiveProvider
{
    /** Indodax pairs for the theme's crypto symbols. */
    private const PAIRS = [
        'btc' => 'btc',
        'eth' => 'eth',
        'sol' => 'sol',
        'usdt' => 'usdt',
        'xrp' => 'xrp',
    ];

    public function id(): string
    {
        return 'indodax';
    }

    public function label(): string
    {
        return 'Indodax (live crypto, free)';
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

        foreach (array_keys(self::PAIRS) as $symbol) {
            if (isset($symbols[$symbol])) {
                $supplied[$symbol] = $symbols[$symbol];
            }
        }

        return $supplied;
    }

    protected function transientKey(): string
    {
        return 'kk_crypto_indodax';
    }

    protected function lastGoodOption(): string
    {
        return 'kk_crypto_indodax_last_good';
    }

    /**
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $now = time();
        $prices = [];

        foreach (self::PAIRS as $symbol => $pair) {
            $data = $this->getJson('https://indodax.com/api/ticker/' . $pair . 'idr');

            if ($data === null) {
                continue;
            }

            $price = $data['ticker']['last'] ?? null;

            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }

            $prices[$symbol] = [
                'price' => (float) $price,
                'change' => null,
                'updated' => $now,
            ];
        }

        return $prices !== [] ? $prices : null;
    }
}

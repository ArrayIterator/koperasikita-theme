<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Shared base for the live (API) price providers: Xaus (gold spot),
 * CoinGecko and Indodax (crypto), Yahoo Finance (stocks).
 *
 * Every live provider follows the same rules:
 *  - A transient caches the fetched prices (default 15 minutes), so
 *    one fetch serves every visitor until it expires.
 *  - The last good value is kept in an option: when the live fetch
 *    fails (upstream down, timeout), the stale value is served
 *    instead of an empty state, with its real `updated` timestamp
 *    so the age shown is honest.
 *  - When there is no cached value and no last good value, prices()
 *    returns an empty array — never an invented number.
 *  - A provider must never fatal on bad input; an unreachable
 *    upstream answers with an empty array, not an exception.
 *
 * A concrete provider only implements fetch(): one upstream call
 * that returns the symbol map, or null on any failure.
 */
abstract class AbstractLiveProvider implements MarketPriceProvider
{
    abstract protected function transientKey(): string;

    abstract protected function lastGoodOption(): string;

    protected function cacheSeconds(): int
    {
        return 900; // 15 minutes.
    }

    /**
     * The public API URL the browser may call directly (client-side)
     * for this provider, or null when the upstream does not support
     * browser calls (no CORS, or one request per symbol). Xaus and
     * CoinGecko are CORS-enabled and keyless, so the browser calls
     * them directly and the server is only a fallback; Indodax needs
     * one request per coin and Yahoo Finance has no CORS, so those
     * two are always served through the backend endpoint.
     */
    public function clientApiUrl(): ?string
    {
        return null;
    }

    /**
     * One upstream call for the market.
     *
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     *   Prices keyed by symbol, or null on any failure.
     */
    abstract protected function fetch(string $market): ?array;

    public function prices(string $market): array
    {
        if (!$this->supports($market)) {
            return [];
        }

        // 1. Fresh cache (transient).
        $cached = get_transient($this->transientKey());
        if (is_array($cached) && $cached !== []) {
            return $this->toMarketPrices($market, $cached);
        }

        // 2. Live fetch.
        $fetched = $this->fetch($market);
        if (is_array($fetched) && $fetched !== []) {
            set_transient($this->transientKey(), $fetched, $this->cacheSeconds());
            update_option($this->lastGoodOption(), $fetched, false);

            return $this->toMarketPrices($market, $fetched);
        }

        // 3. Stale fallback: the last good value, with its real
        // timestamp so the age shown is honest.
        $last = get_option($this->lastGoodOption());
        if (is_array($last) && $last !== []) {
            return $this->toMarketPrices($market, $last);
        }

        // 4. No data at all.
        return [];
    }

    /**
     * Turn a fetched symbol map into MarketPrice objects, keeping
     * only symbols the market actually defines and rows that carry
     * a positive price.
     *
     * @param array<string, mixed> $data
     * @return array<string, MarketPrice>
     */
    protected function toMarketPrices(string $market, array $data): array
    {
        $labels = Markets::symbols($market);
        $prices = [];

        foreach ($data as $symbol => $entry) {
            if (!is_string($symbol) || !isset($labels[$symbol])) {
                continue;
            }

            if (!is_array($entry) || !isset($entry['price'])) {
                continue;
            }

            $price = (float) $entry['price'];

            if ($price <= 0) {
                continue;
            }

            $change = isset($entry['change']) && is_numeric($entry['change'])
                ? (float) $entry['change']
                : null;
            $updated = isset($entry['updated']) ? (int) $entry['updated'] : time();

            $prices[$symbol] = new MarketPrice(
                $market,
                $symbol,
                $labels[$symbol],
                $price,
                $change,
                Markets::currency($market),
                $updated
            );
        }

        return $prices;
    }

    /**
     * One GET request that returns decoded JSON, or null on any
     * failure (network error, non-200 status, bad JSON).
     *
     * @return array<string, mixed>|null
     */
    protected function getJson(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }
}

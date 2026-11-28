<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;
use KoperasiKita\Features\MarketSync;

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
 *  - A live fetch from the frontend is only allowed when the
 *    stored data is missing or stale: no fresh transient and no
 *    last-good option, or a last-good value whose newest entry
 *    is older than the auto-sync interval (the cron's maximum
 *    time between syncs). While stored data is fresh it is
 *    served from the DB and never re-fetched from the frontend -
 *    fresh data is the sync's job (manual sync in wp-admin, cron
 *    auto-sync), not the page render's.
 *  - While a fetch runs, a "fetching" record sits in the DB
 *    (a short-lived transient per provider): any other request
 *    that would fetch sees it and stands down instead of firing
 *    a duplicate upstream call. The record self-expires, so a
 *    crashed or timed-out fetch can never leave a permanent
 *    lock behind.
 *  - When there is no cached value and no last good value, prices()
 *    returns an empty array - never an invented number.
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

    /**
     * How long a failed fetch stays "recently failed" (seconds).
     * While the backoff transient is set, prices() serves the last
     * good value (or nothing) without touching the upstream again -
     * a down provider must not burn a full timeout on every render.
     */
    protected function failedBackoffSeconds(): int
    {
        return 60;
    }

    /** Transient key marking this provider as recently failed. */
    protected function failedKey(): string
    {
        return $this->transientKey() . '__failed';
    }

    /**
     * How long a fetch is allowed to hold the "fetching" record
     * (seconds). A single fetch is one 8-second upstream call per
     * chunk at most, so 120 seconds is far above any healthy
     * fetch and still short enough that a crashed or timed-out
     * fetch can never block the next one for long - the
     * transient expires on its own.
     */
    protected function fetchingTtlSeconds(): int
    {
        return 120;
    }

    /**
     * Transient key marking this provider as mid-fetch. The value
     * is the Unix time the fetch started; the key exists only
     * while a fetch is in progress (set before fetch(), deleted
     * when it finishes either way) and self-expires after
     * fetchingTtlSeconds(), so a fetch that never returns cannot
     * leave a permanent lock behind.
     */
    protected function fetchingKey(): string
    {
        return $this->transientKey() . '__fetching';
    }

    /**
     * How old stored data may be before the frontend is allowed
     * to fetch it live (seconds): the auto-sync interval from
     * the MarketSync settings - the cron's maximum time between
     * syncs. While the stored data is newer than this, the render
     * serves the DB and the upstream is not touched. Auto-sync
     * off falls back to the default interval (once an hour), so
     * a disabled schedule never leaves the frontend fetching
     * on every render.
     */
    protected function freshnessWindowSeconds(): int
    {
        $settings = MarketSync::autoSyncSettings();
        $hours = $settings['enabled'] ? $settings['interval_hours'] : MarketSync::DEFAULT_INTERVAL_HOURS;

        return $hours * HOUR_IN_SECONDS;
    }

    /**
     * Whether a stored symbol map is still fresh: its newest
     * entry is inside the freshness window. A map with no
     * usable timestamp is treated as stale - its age cannot be
     * proven, so the fetch is allowed rather than serving data
     * of unknown age.
     *
     * @param array<string, array{price: float, change: float|null, updated: int}> $map
     */
    protected function storedIsFresh(array $map): bool
    {
        $newest = 0;

        foreach ($map as $entry) {
            if (is_array($entry) && isset($entry['updated']) && is_numeric($entry['updated'])) {
                $newest = max($newest, (int) $entry['updated']);
            }
        }

        if ($newest <= 0) {
            return false;
        }

        return time() - $newest <= $this->freshnessWindowSeconds();
    }

    /** Provider ids already fetched (or attempted) in this request. */
    private static array $attemptedThisRequest = [];

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

        // Fresh cache (transient).
        $cached = get_transient($this->transientKey());
        if (is_array($cached) && $cached !== []) {
            return $this->toMarketPrices($market, $cached);
        }

        // Stored data (last good): while it is fresh - its newest
        // entry inside the auto-sync interval - serve it from the
        // DB and never fetch from the frontend. The sync (manual
        // in wp-admin, cron auto-sync) is what refreshes stored
        // data, not the page render. A cold transient used to
        // re-fetch on every render; now it just serves the last
        // good value until the sync runs.
        $last = get_option($this->lastGoodOption());
        $hasStored = is_array($last) && $last !== [];

        if ($hasStored && $this->storedIsFresh($last)) {
            return $this->toMarketPrices($market, $last);
        }

        // No stored data, or stored but stale (older than the
        // auto-sync interval): a live fetch is allowed, but only
        // one request at a time, and never while the 60-second
        // failure backoff is set. A provider that just failed
        // answers from its last good value (or nothing) instead
        // of burning another timeout.
        //
        // Never in block-editor preview mode (Markets::isPreviewMode):
        // the inserter/canvas SSR render must serve the transient or
        // the last good value, never burn an 8-second upstream
        // timeout while a cold transient refetches - that is what
        // left the inserter preview a blank white box for ~5s.
        //
        // The fetching record is the cross-request lock: while
        // another request is mid-fetch, this one stands down and
        // answers from whatever is stored (or nothing) instead of
        // firing a duplicate upstream call. The record is a
        // short-lived transient, so a fetch that never returns
        // expires on its own and the next request may try again.
        // (The check-then-set is not atomic, so two requests
        // landing in the same instant can rarely both fetch - the
        // stampede of N fetches becomes at most a couple, never
        // one per visitor.)
        $key = $this->transientKey();

        if (
            Markets::isPreviewMode()
            || isset(self::$attemptedThisRequest[$key])
            || get_transient($this->failedKey()) !== false
            || get_transient($this->fetchingKey()) !== false
        ) {
            // Blocked: the stale last-good value is still better
            // than an empty state, with its real timestamp so the
            // age shown is honest.
            if ($hasStored) {
                return $this->toMarketPrices($market, $last);
            }

            return [];
        }

        self::$attemptedThisRequest[$key] = true;
        set_transient($this->fetchingKey(), time(), $this->fetchingTtlSeconds());

        try {
            $fetched = $this->fetch($market);
        } finally {
            delete_transient($this->fetchingKey());
        }

        if (is_array($fetched) && $fetched !== []) {
            set_transient($this->transientKey(), $fetched, $this->cacheSeconds());
            update_option($this->lastGoodOption(), $fetched, false);
            delete_transient($this->failedKey());

            return $this->toMarketPrices($market, $fetched);
        }

        // The fetch failed: mark the provider as recently failed
        // so the next render inside the backoff window does not
        // retry the upstream. The stale last-good value below is
        // still served, with its real timestamp.
        set_transient($this->failedKey(), 1, $this->failedBackoffSeconds());

        if ($hasStored) {
            return $this->toMarketPrices($market, $last);
        }

        // No data at all.
        return [];
    }

    /**
     * Force a fresh upstream fetch and store it, regardless of the
     * transient or the failure backoff: this is the sync path (the
     * Market Index manual sync and the daily cron), not the page
     * render path. A manual sync must actually fetch even when the
     * last render just failed, so the admin sees a fresh result
     * instead of the backoff. Returns the fetched symbol map, or
     * null when the fetch failed (the backoff is set, the last
     * good value stays untouched so the strip keeps serving it).
     *
     * The sync is authoritative, so it is never blocked by a
     * frontend "fetching" record - but it still sets the record
     * while it runs and clears it when it finishes either way, so
     * a render arriving mid-sync stands down instead of firing a
     * second upstream call for the same data.
     *
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    public function forceRefresh(string $market): ?array
    {
        if (!$this->supports($market)) {
            return null;
        }

        set_transient($this->fetchingKey(), time(), $this->fetchingTtlSeconds());

        try {
            $fetched = $this->fetch($market);
        } finally {
            delete_transient($this->fetchingKey());
        }

        if (!is_array($fetched) || $fetched === []) {
            set_transient($this->failedKey(), 1, $this->failedBackoffSeconds());

            return null;
        }

        set_transient($this->transientKey(), $fetched, $this->cacheSeconds());
        update_option($this->lastGoodOption(), $fetched, false);
        delete_transient($this->failedKey());

        return $fetched;
    }

    /**
     * Stored prices only: the fresh transient, else the last-good
     * option. Never fetches upstream, so a page render that serves
     * stored prices never stalls on the network - a cold transient
     * used to block the whole render on an 8-second upstream call,
     * which is what left the gold slider empty on the first load
     * and made the data arrive late, like a debounce. Callers that
     * need a value on the first paint use this; the live fetch is
     * the last resort when nothing is stored at all (see prices()).
     *
     * @return array<string, MarketPrice>
     */
    public function storedPrices(string $market): array
    {
        if (!$this->supports($market)) {
            return [];
        }

        $cached = get_transient($this->transientKey());
        if (is_array($cached) && $cached !== []) {
            return $this->toMarketPrices($market, $cached);
        }

        $last = get_option($this->lastGoodOption());
        if (is_array($last) && $last !== []) {
            return $this->toMarketPrices($market, $last);
        }

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

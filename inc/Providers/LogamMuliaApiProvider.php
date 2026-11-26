<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live gold provider (Logam Mulia API, free, no API key).
 *
 * Fetches retail gold prices from the public Logam Mulia API
 * (https://logam-mulia-api.iamutaki.workers.dev), a Cloudflare
 * Workers app that scrapes the Indonesian gold retailers and
 * serves the results as JSON. There is NO CSV endpoint in the
 * upstream at all, so the JSON API is the source — the earlier
 * "fetch the CSV" instruction cannot be followed literally.
 * Three endpoints are read (one request each, only the rows
 * needed):
 *  - galeri24: 1-gram Galeri 24 + UBS sell/buyback prices.
 *  - hargaemas-net: 1-gram Antam sell/buyback price. Galeri 24's
 *    own ANTAM section returns 0 for 1 gram today, so Antam comes
 *    from Harga Emas.net; Galeri 24's ANTAM row is the fallback.
 *  - pegadaian: Pegadaian 0.01-gram price, scaled x100 to 1 gram.
 *
 * Logam Mulia (the official Antam source) is DOWN upstream — its
 * Jina Reader API key is dead (HTTP 401) — so it is not used.
 * EmasKita is not in the upstream at all and stays manual. Spot
 * is NOT supplied: none of these endpoints is a spot price (they
 * are retail brand prices); spot stays with Xaus.
 *
 * Every fetched price is stored in the kk_gold_prices custom table
 * (GoldPricesTable), which is also the last-good fallback when the
 * upstream is unreachable — so the widget keeps showing the last
 * real price with its real timestamp, never an invented one. The
 * 15-minute transient is kept for the hot path. A source that
 * fails or returns success:false is skipped, never failing the
 * whole provider.
 *
 * MarketPrice carries only the sell price (it has no buyback
 * field); the buyback price is stored in the table alongside.
 */
final class LogamMuliaApiProvider extends AbstractLiveProvider
{
    public const API_BASE = 'https://logam-mulia-api.iamutaki.workers.dev';

    public function id(): string
    {
        return 'logam-mulia-api';
    }

    public function label(): string
    {
        return 'Logam Mulia API (live Antam, Galeri 24, UBS, Pegadaian)';
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
        $supplied = [];

        foreach (['antam', 'ubs', 'galeri-24', 'pegadaian'] as $symbol) {
            if (isset($symbols[$symbol])) {
                $supplied[$symbol] = $symbols[$symbol];
            }
        }

        return $supplied;
    }

    protected function transientKey(): string
    {
        return 'kk_gold_logam_mulia';
    }

    protected function lastGoodOption(): string
    {
        // The custom table (GoldPricesTable) is the last-good store,
        // not an option; this only satisfies the abstract contract.
        return 'kk_gold_logam_mulia_last_good';
    }

    /**
     * Prices with the custom table as the last-good fallback.
     * Mirrors AbstractLiveProvider::prices() but reads the stale
     * value from kk_gold_prices (and writes every fetch there),
     * so the last real price survives with its real timestamp.
     *
     * @return array<string, MarketPrice>
     */
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

        // 2. Live fetch; every row is stored in the custom table.
        $fetched = $this->fetch($market);
        if (is_array($fetched) && $fetched !== []) {
            set_transient($this->transientKey(), $fetched, $this->cacheSeconds());
            $this->storeRows($fetched);

            return $this->toMarketPrices($market, $fetched);
        }

        // 3. Stale fallback: the last good rows from the table,
        // with their real fetched timestamps.
        $last = GoldPricesTable::lastGood($this->id());
        if ($last !== []) {
            $map = [];
            foreach ($last as $symbol => $row) {
                $map[$symbol] = [
                    'price' => $row['sell_price'],
                    'change' => null,
                    'updated' => $row['fetched_at'],
                ];
            }

            return $this->toMarketPrices($market, $map);
        }

        // 4. No data at all.
        return [];
    }

    /**
     * One fetch per endpoint (galeri24, hargaemas-net, pegadaian).
     * A failed endpoint is skipped; the rest still map.
     *
     * The map carries the parent's price/change/updated shape plus
     * buyback + recorded_date for the table — toMarketPrices()
     * ignores the extra keys.
     *
     * @return array<string, array{price: float, change: float|null, updated: int, buyback: float|null, recorded_date: string|null}>|null
     */
    protected function fetch(string $market): ?array
    {
        $now = time();
        $prices = [];

        // Galeri 24: 1-gram rows for GALERI 24, UBS, ANTAM.
        $galeriRows = $this->rows($this->getJson(self::API_BASE . '/api/prices/galeri24'));

        $galeri24 = $this->oneGramRow($galeriRows, 'GALERI 24');
        if ($galeri24 !== null) {
            $prices['galeri-24'] = $this->rowToEntry($galeri24, 1.0, $now);
        }

        $ubs = $this->oneGramRow($galeriRows, 'UBS');
        if ($ubs !== null) {
            $prices['ubs'] = $this->rowToEntry($ubs, 1.0, $now);
        }

        $antamGaleri = $this->oneGramRow($galeriRows, 'ANTAM');

        // Harga Emas.net: 1-gram Antam. This is the Antam source —
        // Galeri 24's own ANTAM section returns 0 for 1 gram, so a
        // positive Antam price comes from here; Galeri 24's ANTAM
        // row is the fallback when this endpoint fails.
        $hargaRows = $this->rows($this->getJson(self::API_BASE . '/api/prices/hargaemas-net'));
        $antamHarga = null;
        foreach ($hargaRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((float) ($row['weight'] ?? 0) !== 1.0) {
                continue;
            }

            if (stripos((string) ($row['materialType'] ?? ''), 'antam') === false) {
                continue;
            }

            if (!is_numeric($row['sellPrice'] ?? null) || (float) $row['sellPrice'] <= 0) {
                continue;
            }

            $antamHarga = $row;
            break;
        }

        $antam = $antamHarga ?? $antamGaleri;
        if ($antam !== null) {
            $prices['antam'] = $this->rowToEntry($antam, 1.0, $now);
        }

        // Pegadaian: a single 0.01-gram row, scaled to 1 gram.
        $pegRows = $this->rows($this->getJson(self::API_BASE . '/api/prices/pegadaian'));
        foreach ($pegRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $weight = (float) ($row['weight'] ?? 0);
            if ($weight <= 0) {
                continue;
            }

            $sell = $row['sellPrice'] ?? null;
            if (!is_numeric($sell) || (float) $sell <= 0) {
                continue;
            }

            $prices['pegadaian'] = $this->rowToEntry($row, 1.0 / $weight, $now);
            break;
        }

        // Drop any entry that failed to map (rowToEntry is only
        // null when the price is not positive — already guarded
        // above, but never trust a single check).
        $prices = array_filter($prices, static fn ($entry) => is_array($entry));

        return $prices !== [] ? $prices : null;
    }

    /**
     * Decoded API rows, or [] when the call failed, the payload is
     * not the expected shape, or success is not true (e.g. the
     * logammulia endpoint, whose upstream Jina key is dead).
     *
     * @param array<string, mixed>|null $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(?array $data): array
    {
        if ($data === null || ($data['success'] ?? false) !== true) {
            return [];
        }

        $rows = $data['data'] ?? null;

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn ($row) => is_array($row)));
    }

    /**
     * The 1-gram row for one material type, or null when it is
     * missing or its sell price is not positive. A zero price is
     * the upstream's "no data" for that row (Galeri 24's ANTAM
     * 1-gram row is 0 today), so it never maps to a price.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function oneGramRow(array $rows, string $materialType): ?array
    {
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['materialType'] ?? ''), $materialType) !== 0) {
                continue;
            }

            if ((float) ($row['weight'] ?? 0) !== 1.0) {
                continue;
            }

            if (!is_numeric($row['sellPrice'] ?? null) || (float) $row['sellPrice'] <= 0) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * One upstream row into a fetch-map entry. $scale converts the
     * row's weight to 1 gram (1.0 for 1-gram rows, 100 for
     * Pegadaian's 0.01-gram row).
     *
     * @param array<string, mixed> $row
     * @return array{price: float, change: null, updated: int, buyback: float|null, recorded_date: string|null}
     */
    private function rowToEntry(array $row, float $scale, int $now): array
    {
        $buyback = $row['buybackPrice'] ?? null;

        return [
            'price' => (float) $row['sellPrice'] * $scale,
            'change' => null,
            'updated' => $now,
            'buyback' => is_numeric($buyback) && (float) $buyback > 0
                ? (float) $buyback * $scale
                : null,
            'recorded_date' => $this->recordedDate($row),
        ];
    }

    /** @param array<string, mixed> $row */
    private function recordedDate(array $row): ?string
    {
        $date = (string) ($row['recordedDate'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /**
     * Persist every fetched row in the custom table. A failed
     * write is ignored — the price response must never break
     * because the table write failed.
     *
     * @param array<string, array<string, mixed>> $map
     */
    private function storeRows(array $map): void
    {
        foreach ($map as $symbol => $entry) {
            if (!is_string($symbol) || !is_array($entry)) {
                continue;
            }

            $price = (float) ($entry['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }

            GoldPricesTable::upsert(
                $this->id(),
                $symbol,
                $price,
                isset($entry['buyback']) && is_numeric($entry['buyback'])
                    ? (float) $entry['buyback']
                    : null,
                'IDR',
                isset($entry['recorded_date']) && is_string($entry['recorded_date'])
                    ? $entry['recorded_date']
                    : null,
                (int) ($entry['updated'] ?? time())
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Api\Hooks;
use KoperasiKita\Features\Markets;

/**
 * Live stock provider (Yahoo Finance, free, no API key).
 *
 * Fetches IDX prices from Yahoo Finance's public spark endpoint
 * (`v8/finance/spark`), one batched request per chunk of symbols —
 * the v7/v6 quote endpoints answer `Unauthorized`/`Not Found`
 * from this server, and the v8 chart endpoint only takes one
 * symbol per request, so spark is the only batched endpoint that
 * works (verified live 2026-11-27: 47/47 symbols, see
 * ~/workspace/kk-verify-stocks/live-check.php). Spark returns
 * `fulldayPrice` + `fulldayChangePercent` per symbol; a symbol
 * that returns no price is left out, never invented.
 *
 * The catalogue is the LQ45: IHSG (^JKSE) + LQ45 (^JKLQ45) +
 * the 45 constituents per the IDX announcements
 * Peng-00205/BEI.POP/10-2026 (27 Oct 2026) and the 11 Nov 2026
 * revision (MAPI/NCKL/PGEO out, ERAA/GGRM/PWON in), effective
 * 18 Nov 2026 – 29 Jan 2027. Every code was verified live
 * against Yahoo before it was added — re-check with
 * ~/workspace/kk-verify-stocks/live-check.php any time, and
 * update the catalogue in Markets::symbols('stock') when IDX
 * revises the index (it is reviewed every January, April,
 * July and October).
 *
 * What this provider supplies by default is deliberately smaller
 * than the catalogue: the 2 indices + the 12 heaviest LQ45
 * constituents. The homepage strip and the widgets render every
 * supplied symbol as a row, so supplying all 47 by default would
 * make them 47 rows tall. The full catalogue stays available for
 * manual snapshots and Data Desk panels, and a site can widen
 * the live set up to the full catalogue through the
 * `koperasikita/markets/yahoo_stock_symbols` filter.
 *
 * Caching and the stale-value fallback live in AbstractLiveProvider
 * (15-minute transient + last good value in an option). A partial
 * spark response keeps the last good values for the symbols that
 * did not come back, so one missing symbol never blanks the rest.
 */
final class YahooStockProvider extends AbstractLiveProvider
{
    /**
     * Yahoo spark takes at most ~12 symbols per request in
     * practice (47 in one request answers HTTP 400); the fetch
     * splits the list into chunks of this size.
     */
    private const CHUNK_SIZE = 12;

    /** The 2 indices + the 12 heaviest LQ45 constituents. */
    private const DEFAULT_SLUGS = [
        'ihsg',
        'lq45',
        'bbca',
        'bbri',
        'bmri',
        'tlkm',
        'asii',
        'ammn',
        'bbni',
        'icbp',
        'untr',
        'aadi',
        'amrt',
        'antm',
    ];

    public function id(): string
    {
        return 'yahoo';
    }

    public function label(): string
    {
        return 'Yahoo Finance (live IDX, free)';
    }

    public function supports(string $market): bool
    {
        return $market === 'stock';
    }

    /**
     * The Yahoo Finance symbol for a catalogue slug, or null when
     * the slug is not a known stock: indices use their ^ symbols,
     * stocks use the code + `.JK`.
     */
    public static function yahooSymbol(string $slug): ?string
    {
        if ($slug === 'ihsg') {
            return '^JKSE';
        }

        if ($slug === 'lq45') {
            return '^JKLQ45';
        }

        $symbols = Markets::symbols('stock');

        if (!isset($symbols[$slug])) {
            return null;
        }

        return strtoupper($slug) . '.JK';
    }

    /**
     * Every catalogue symbol with its Yahoo symbol (slug =>
     * Yahoo symbol). Used by the verification harness and by
     * sites that widen the live set through the filter.
     *
     * @return array<string, string>
     */
    public static function allYahooSymbols(): array
    {
        $out = [];

        foreach (Markets::symbols('stock') as $slug => $label) {
            $yahoo = self::yahooSymbol($slug);

            if ($yahoo !== null) {
                $out[$slug] = $yahoo;
            }
        }

        return $out;
    }

    public function supplies(string $market): array
    {
        if ($market !== 'stock') {
            return [];
        }

        $catalogue = Markets::symbols('stock');
        $slugs = self::DEFAULT_SLUGS;

        $filtered = apply_filters(Hooks::MARKETS_YAHOO_STOCK_SYMBOLS, $slugs, $market);

        if (is_array($filtered) && $filtered !== []) {
            $slugs = $filtered;
        }

        $supplies = [];

        foreach ($slugs as $slug) {
            if (is_string($slug) && isset($catalogue[$slug])) {
                $supplies[$slug] = $catalogue[$slug];
            }
        }

        return $supplies;
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
     * One batched spark request per chunk of supplied symbols.
     *
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $supplied = $this->supplies($market);

        if ($supplied === []) {
            return null;
        }

        $yahoo = [];

        foreach (array_keys($supplied) as $slug) {
            $symbol = self::yahooSymbol((string) $slug);

            if ($symbol !== null) {
                $yahoo[(string) $slug] = $symbol;
            }
        }

        if ($yahoo === []) {
            return null;
        }

        $fetched = [];

        foreach (array_chunk($yahoo, self::CHUNK_SIZE, true) as $chunk) {
            $url = 'https://query1.finance.yahoo.com/v8/finance/spark?symbols='
                . implode(',', array_map('rawurlencode', array_values($chunk)))
                . '&range=1d&interval=1d';
            $data = $this->getJson($url);

            if ($data === null) {
                continue;
            }

            foreach ($chunk as $slug => $symbol) {
                $row = $data[$symbol] ?? null;

                if (!is_array($row)) {
                    continue;
                }

                $price = $row['fulldayPrice'] ?? ($row['close'][0] ?? null);

                if (!is_numeric($price) || (float) $price <= 0) {
                    continue;
                }

                $change = $row['fulldayChangePercent'] ?? null;

                $fetched[$slug] = [
                    'price' => (float) $price,
                    'change' => is_numeric($change) ? (float) $change : null,
                    'updated' => time(),
                ];
            }
        }

        if ($fetched === []) {
            return null;
        }

        // Partial response: keep the last good values for the
        // symbols that did not come back this round, so one
        // missing symbol never blanks the rest of the list.
        $last = get_option($this->lastGoodOption());

        if (is_array($last)) {
            foreach ($last as $slug => $entry) {
                if (!isset($fetched[$slug]) && isset($supplied[$slug]) && is_array($entry) && isset($entry['price'])) {
                    $fetched[$slug] = $entry;
                }
            }
        }

        return $fetched;
    }
}

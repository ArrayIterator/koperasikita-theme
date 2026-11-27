<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Custom table for fetched gold prices (`{prefix}kk_gold_prices`).
 *
 * One row per brand per day — the UNIQUE key is (provider, symbol,
 * recorded_date), so the table keeps a per-day history (the graph on
 * the admin page reads it) and a re-run can never create duplicates.
 * The last-good fallback reads the latest row per symbol.
 *
 * The table is created lazily on the first write (dbDelta, guarded
 * by a version option): the theme has no activation hook that would
 * run on the already-live site, so the first successful sync
 * creates it. Version 2 changed the unique key from
 * (provider, symbol) to (provider, symbol, recorded_date) so the
 * table keeps history instead of only the latest row per brand.
 *
 * Columns: id, provider, symbol, sell_price, buyback_price,
 * currency, recorded_date (the upstream's date for the price),
 * fetched_at (Unix timestamp of the fetch).
 */
final class GoldPricesTable
{
    public const VERSION = '2';

    public const VERSION_OPTION = 'kk_gold_prices_table_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kk_gold_prices';
    }

    public static function maybeCreate(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(64) NOT NULL,
            symbol varchar(64) NOT NULL,
            sell_price decimal(15,2) NOT NULL,
            buyback_price decimal(15,2) DEFAULT NULL,
            currency varchar(3) NOT NULL DEFAULT 'IDR',
            recorded_date date DEFAULT NULL,
            fetched_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_symbol_date (provider, symbol, recorded_date)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /**
     * Insert one row, ignoring it when a row with the same
     * (provider, symbol, recorded_date) already exists — the UNIQUE
     * key enforces it, so a re-run or a double-run can never create
     * duplicates. Returns 1 when inserted, 0 when skipped
     * (already in the DB) or when the write failed.
     */
    public static function insertIgnore(
        string $provider,
        string $symbol,
        float $sell,
        ?float $buyback,
        string $currency,
        string $recordedDate,
        int $fetchedAt
    ): int {
        self::maybeCreate();

        global $wpdb;

        $table = self::table();

        // $wpdb->prepare() has no clean NULL for %f, so a null
        // buyback is inlined as SQL NULL.
        $buybackSql = $buyback === null
            ? 'NULL'
            : $wpdb->prepare('%f', $buyback);

        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at)
             VALUES (%s, %s, %f, {$buybackSql}, %s, %s, %d)",
            $provider,
            $symbol,
            $sell,
            $currency,
            $recordedDate,
            $fetchedAt
        );

        $result = $wpdb->query($sql);

        return $result === false ? 0 : (int) $result;
    }

    /**
     * The newest recordedDate stored for one provider, or null when
     * the table is empty. The sync's coarse fast-path: a CSV whose
     * max date is older than this holds nothing new.
     */
    public static function maxRecordedDate(string $provider): ?string
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        $max = $wpdb->get_var(
            $wpdb->prepare("SELECT MAX(recorded_date) FROM {$table} WHERE provider = %s", $provider)
        );

        return is_string($max) && $max !== '' ? $max : null;
    }

    /**
     * All rows for one provider, newest first. Used by the admin
     * page (list + graph). Capped so a long history stays light.
     *
     * @return array<int, array{symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    public static function history(string $provider, int $limit = 200): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} WHERE provider = %s
                 ORDER BY recorded_date DESC, symbol ASC LIMIT %d",
                $provider,
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return self::cleanRows($rows);
    }

    /**
     * Filtered rows for one provider, newest first — the History
     * tab's chart (capped, page 0) and table (paged) both read
     * through here, so the chart and the list can never disagree
     * about the filter. An empty $provider means "all providers".
     *
     * Filters: ['symbol' => slug or '', 'from' => 'Y-m-d' or '',
     * 'to' => 'Y-m-d' or '']. Empty values mean "no filter".
     * Dates that are not real Y-m-d dates are ignored here too,
     * so a hand-made URL can never break the query.
     *
     * @param array{symbol?: string, from?: string, to?: string} $filters
     * @return array<int, array{symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    public static function historyFiltered(string $provider, array $filters, int $limit = 200, int $offset = 0): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        [$where, $params] = self::historyWhere($provider, $filters);
        $params[] = max(0, $limit);
        $params[] = max(0, $offset);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} {$where}
                 ORDER BY recorded_date DESC, symbol ASC LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return self::cleanRows($rows);
    }

    /**
     * How many rows the same filter matches — the History tab's
     * page count. An empty $provider means "all providers".
     * Counts table rows, so a row cleanRows() would
     * drop (no symbol, no positive price) is still counted; the
     * sync only ever inserts valid rows, so the count matches
     * what the table shows.
     *
     * @param array{symbol?: string, from?: string, to?: string} $filters
     */
    public static function historyCount(string $provider, array $filters): int
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }

        [$where, $params] = self::historyWhere($provider, $filters);

        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", ...$params));

        return $count === null ? 0 : (int) $count;
    }

    /**
     * The shared WHERE clause for the filtered history queries:
     * provider first (omitted when empty — "all providers"),
     * then the optional symbol and date filters.
     *
     * @param array{symbol?: string, from?: string, to?: string} $filters
     * @return array{string, array<int, string|int>}
     */
    private static function historyWhere(string $provider, array $filters): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];

        if ($provider !== '') {
            $where .= ' AND provider = %s';
            $params[] = $provider;
        }

        $symbol = (string) ($filters['symbol'] ?? '');
        if ($symbol !== '') {
            $where .= ' AND symbol = %s';
            $params[] = $symbol;
        }

        $from = self::historyDate((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $where .= ' AND recorded_date >= %s';
            $params[] = $from;
        }

        $to = self::historyDate((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $where .= ' AND recorded_date <= %s';
            $params[] = $to;
        }

        return [$where, $params];
    }

    /**
     * Every provider id that has at least one row, alphabetically.
     * The History tab's provider filter reads this, so a future
     * provider's history appears automatically and nothing about
     * providers is hardcoded on the page.
     *
     * @return array<int, string>
     */
    public static function providers(): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $rows = $wpdb->get_col("SELECT DISTINCT provider FROM {$table} ORDER BY provider ASC");

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $rows),
            static fn(string $provider): bool => $provider !== ''
        ));
    }

    /**
     * A real Y-m-d date, or '' — anything else (garbage, a
     * wrong format, an impossible date) means "no filter".
     */
    private static function historyDate(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /**
     * Last-good rows for one provider (the latest row per symbol),
     * keyed by symbol. An empty array means the table does not exist
     * yet or holds no rows — never an error.
     *
     * @return array<string, array{sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}>
     */
    public static function lastGood(string $provider): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} WHERE provider = %s ORDER BY recorded_date DESC",
                $provider
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        // Newest first, so the first row seen per symbol is its latest.
        $good = [];

        foreach (self::cleanRows($rows) as $row) {
            if (!isset($good[$row['symbol']])) {
                $good[$row['symbol']] = $row;
            }
        }

        return $good;
    }

    /**
     * Normalise raw DB rows into typed arrays, dropping rows with no
     * symbol or no positive sell price.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array{symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    private static function cleanRows(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $symbol = (string) ($row['symbol'] ?? '');
            $sell = (float) ($row['sell_price'] ?? 0);

            if ($symbol === '' || $sell <= 0) {
                continue;
            }

            $clean[] = [
                'symbol' => $symbol,
                'sell_price' => $sell,
                'buyback_price' => isset($row['buyback_price']) && $row['buyback_price'] !== null
                    ? (float) $row['buyback_price']
                    : null,
                'currency' => (string) ($row['currency'] ?? 'IDR'),
                'recorded_date' => (string) ($row['recorded_date'] ?? ''),
                'fetched_at' => (int) ($row['fetched_at'] ?? 0) > 0
                    ? (int) $row['fetched_at']
                    : time(),
            ];
        }

        return $clean;
    }
}

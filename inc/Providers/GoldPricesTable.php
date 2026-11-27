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
     * Insert one row, or update the row with the same
     * (provider, symbol, recorded_date) when it already exists.
     * The update rewrites the price and the fetch time; a null
     * buyback leaves the stored buyback alone, so an update from
     * a source without buyback data never blanks one. Returns
     * 1 inserted, 2 updated, 0 when the row already held the
     * same values (or the write failed).
     */
    public static function upsert(
        string $provider,
        string $symbol,
        float $sell,
        ?float $buyback,
        string $currency,
        string $recordedDate,
        int $fetchedAt
    ): int {
        $inserted = self::insertIgnore($provider, $symbol, $sell, $buyback, $currency, $recordedDate, $fetchedAt);

        if ($inserted > 0) {
            return 1;
        }

        global $wpdb;

        $data = [
            'sell_price' => $sell,
            'currency' => $currency,
            'fetched_at' => $fetchedAt,
        ];
        $format = ['%f', '%s', '%d'];

        if ($buyback !== null) {
            $data['buyback_price'] = $buyback;
            $format[] = '%f';
        }

        $updated = $wpdb->update(
            self::table(),
            $data,
            ['provider' => $provider, 'symbol' => $symbol, 'recorded_date' => $recordedDate],
            $format,
            ['%s', '%s', '%s']
        );

        return $updated > 0 ? 2 : 0;
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
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
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
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
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
     * Filters: ['symbol' => slug or '', 'symbols' => [slugs] or [],
     * 'from' => 'Y-m-d' or '', 'to' => 'Y-m-d' or '']. Empty values
     * mean "no filter". Dates that are not real Y-m-d dates are
     * ignored here too, so a hand-made URL can never break the
     * query. 'symbols' filters to any of the listed symbols; it
     * is ignored when empty or when 'symbol' already filters to
     * one.
     *
     * $official maps symbol => provider id (see
     * Gold::officialProviders()). When given and no provider
     * filter is set, each brand+date keeps one row: the official
     * provider's row when one exists, else the Logam Mulia row,
     * else the most recently fetched row. A provider filter
     * always shows that provider's rows as stored.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string} $filters
     * @param array<string, string> $official
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    public static function historyFiltered(string $provider, array $filters, int $limit = 200, int $offset = 0, array $official = []): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        if ($official !== [] && $provider === '') {
            $rows = self::preferOfficial(self::historyRows($provider, $filters), $official);

            return array_slice($rows, max(0, $offset), max(0, $limit));
        }

        [$where, $params] = self::historyWhere($provider, $filters);
        $params[] = max(0, $limit);
        $params[] = max(0, $offset);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
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
     * All rows matching a filter, newest first, with no paging:
     * the input for the official-preference pass, which must see
     * every candidate row for a brand+date before it can pick one.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string} $filters
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    private static function historyRows(string $provider, array $filters): array
    {
        global $wpdb;

        $table = self::table();

        [$where, $params] = self::historyWhere($provider, $filters);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} {$where}
                 ORDER BY recorded_date DESC, symbol ASC",
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
     * One row per brand+date, keeping the best-ranked row and the
     * input order (recorded_date DESC, so the table order never
     * shifts). Rank: the symbol's official provider, then the
     * Logam Mulia fallback, then any other provider; ties go to
     * the most recently fetched row.
     *
     * @param array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}> $rows
     * @param array<string, string> $official
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    private static function preferOfficial(array $rows, array $official): array
    {
        $kept = [];
        $at = [];
        $rank = [];

        foreach ($rows as $row) {
            $key = $row['symbol'] . '|' . $row['recorded_date'];
            $r = self::officialRank($row, $official);

            if (!isset($at[$key])) {
                $at[$key] = count($kept);
                $kept[] = $row;
                $rank[] = $r;
                continue;
            }

            $i = $at[$key];

            if ($r < $rank[$i] || ($r === $rank[$i] && $row['fetched_at'] > $kept[$i]['fetched_at'])) {
                $kept[$i] = $row;
                $rank[$i] = $r;
            }
        }

        return $kept;
    }

    /**
     * @param array{provider: string, symbol: string} $row
     * @param array<string, string> $official
     */
    private static function officialRank(array $row, array $official): int
    {
        $provider = (string) ($row['provider'] ?? '');

        if ($provider !== '' && ($official[$row['symbol']] ?? '') === $provider) {
            return 0;
        }

        if ($provider === LogamMuliaApiProvider::PROVIDER_ID) {
            return 1;
        }

        return 2;
    }

    /**
     * How many rows the same filter matches — the History tab's
     * page count. An empty $provider means "all providers".
     * Counts table rows, so a row cleanRows() would
     * drop (no symbol, no positive price) is still counted; the
     * sync only ever inserts valid rows, so the count matches
     * what the table shows. With $official given (and no
     * provider filter) it counts the preferred rows, so the
     * page count matches the deduped table.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string} $filters
     * @param array<string, string> $official
     */
    public static function historyCount(string $provider, array $filters, array $official = []): int
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }

        if ($official !== [] && $provider === '') {
            return count(self::preferOfficial(self::historyRows($provider, $filters), $official));
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
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string} $filters
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

        $symbols = $filters['symbols'] ?? [];
        if ($symbol === '' && is_array($symbols) && $symbols !== []) {
            $clean = array_values(array_unique(array_filter(
                array_map(static fn($slug): string => (string) $slug, $symbols),
                static fn(string $slug): bool => $slug !== ''
            )));
            if ($clean !== []) {
                $placeholders = implode(', ', array_fill(0, count($clean), '%s'));
                $where .= " AND symbol IN ({$placeholders})";
                array_push($params, ...$clean);
            }
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
     * @return array<string, array{provider: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}>
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
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
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
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
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
                'provider' => (string) ($row['provider'] ?? ''),
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

<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Custom table for fetched gold prices (`{prefix}kk_gold_prices`).
 *
 * One row per brand per day - the UNIQUE key is (provider, symbol,
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
     * (provider, symbol, recorded_date) already exists - the UNIQUE
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
     * One stored row by (provider, symbol, recorded_date), or null
     * when there is none. The sync's read-before-write: it decides
     * insert vs update vs fill-missing-buyback from the stored row.
     *
     * @return array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}|null
     */
    public static function rowForDate(string $provider, string $symbol, string $recordedDate): ?array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} WHERE provider = %s AND symbol = %s AND recorded_date = %s LIMIT 1",
                $provider,
                $symbol,
                $recordedDate
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $clean = self::cleanRows([$row]);

        return $clean[0] ?? null;
    }

    /**
     * Fill a stored NULL buyback from a value obtained later, for
     * one (provider, symbol, recorded_date) row. The UPDATE only
     * touches rows whose buyback is NULL, so a stored buyback is
     * never overwritten and the sell price is never touched — this
     * is how a fallback source fills the buyback half of a row
     * another source wrote (and how a manual re-sync fills a
     * buyback that was missing when the row was first stored).
     * Returns 1 when a row was filled, 0 when there was no row or
     * its buyback was already set.
     */
    public static function fillMissingBuyback(
        string $provider,
        string $symbol,
        float $buyback,
        string $recordedDate
    ): int {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET buyback_price = %f
             WHERE provider = %s AND symbol = %s AND recorded_date = %s AND buyback_price IS NULL",
            $buyback,
            $provider,
            $symbol,
            $recordedDate
        ));

        return $result === false ? 0 : (int) $result;
    }

    /**
     * Insert one row, or update the row with the same
     * (provider, symbol, recorded_date) when it already exists.
     * The update only happens when the price really differs and
     * the incoming price is not empty: a stored sell is replaced
     * when the incoming sell differs, a stored buyback is replaced
     * when the incoming buyback differs, and a stored NULL buyback
     * is filled when the incoming buyback is present. A null
     * incoming buyback leaves the stored buyback alone, so an
     * update from a source without buyback data never blanks one.
     * When nothing differs, nothing is written — a re-sync of an
     * unchanged row is a no-op, never an "update". Returns
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

        $existing = self::rowForDate($provider, $symbol, $recordedDate);

        if ($existing === null) {
            return 0;
        }

        // Only write when something really differs — and only with
        // a non-empty incoming price (the caller only ever passes
        // a positive sell; a zero or negative sell never reaches
        // here, so it can never replace a stored price).
        $sellDiffers = $sell > 0 && (float) $existing['sell_price'] !== $sell;
        $buybackDiffers = $buyback !== null
            && $buyback > 0
            && ($existing['buyback_price'] === null || (float) $existing['buyback_price'] !== $buyback);

        if (!$sellDiffers && !$buybackDiffers) {
            return 0;
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
     * Filtered rows for one provider, newest first - the History
     * tab's chart (capped, page 0) and table (paged) both read
     * through here, so the chart and the list can never disagree
     * about the filter. An empty $provider means "all providers".
     *
     * Filters: ['symbol' => slug or '', 'symbols' => [slugs] or [],
     * 'from' => 'Y-m-d' or '', 'to' => 'Y-m-d' or '', 'search' =>
     * text or '']. Empty values mean "no filter". Dates that are
     * not real Y-m-d dates are ignored here too, so a hand-made
     * URL can never break the query. 'symbols' filters to any of
     * the listed symbols; it is ignored when empty or when
     * 'symbol' already filters to one. 'search' matches a brand
     * slug, a provider id or a recorded date.
     *
     * $official maps symbol => provider id (see
     * Gold::officialProviders()). When given and no provider
     * filter is set, each brand+date keeps one row: the official
     * provider's row when one exists, else the Logam Mulia row,
     * else the most recently fetched row. A provider filter
     * always shows that provider's rows as stored.
     *
     * $orderBy is one of date / brand / sell / buyback, $order
     * asc or desc; anything else falls back to date desc, the
     * list the tab always showed. The official-preference pass
     * sorts in PHP after deduping, so a sorted table never shows
     * a brand+date twice.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string, search?: string} $filters
     * @param array<string, string> $official
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    public static function historyFiltered(string $provider, array $filters, int $limit = 200, int $offset = 0, array $official = [], string $orderBy = 'date', string $order = 'desc'): array
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        [$orderKey, $column, $direction] = self::historyOrder($orderBy, $order);

        if ($official !== [] && $provider === '') {
            $rows = self::historySortRows(self::preferOfficial(self::historyRows($provider, $filters), $official), $orderKey, $direction);

            return array_slice($rows, max(0, $offset), max(0, $limit));
        }

        [$where, $params] = self::historyWhere($provider, $filters);
        $params[] = max(0, $limit);
        $params[] = max(0, $offset);

        $orderBySql = $column === 'recorded_date'
            ? "{$column} {$direction}, symbol ASC"
            : ($column === 'symbol'
                ? "{$column} {$direction}, recorded_date DESC"
                : "{$column} {$direction}, recorded_date DESC, symbol ASC");

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                 FROM {$table} {$where}
                 ORDER BY {$orderBySql} LIMIT %d OFFSET %d",
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
     * A list sort request as a validated [key, column,
     * direction]: the key is what the table passes back in the
     * URL, the column is the real DB column. Anything unknown
     * falls back to date desc, the list the tab always showed.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function historyOrder(string $orderBy, string $order): array
    {
        $columns = [
            'date' => 'recorded_date',
            'brand' => 'symbol',
            'sell' => 'sell_price',
            'buyback' => 'buyback_price',
        ];

        $key = isset($columns[$orderBy]) ? $orderBy : 'date';

        return [$key, $columns[$key], strtolower($order) === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * The deduped official-preference rows in list order: the
     * same key order as the SQL branch (primary key, then
     * recorded_date desc, then symbol asc, each used once). A
     * missing buyback sorts like SQL NULL - smallest, so first
     * ascending and last descending.
     *
     * @param array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}> $rows
     * @return array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    private static function historySortRows(array $rows, string $orderKey, string $direction): array
    {
        $flip = $direction === 'desc' ? -1 : 1;

        usort($rows, static function (array $a, array $b) use ($orderKey, $flip): int {
            $cmp = 0;

            if ($orderKey === 'date') {
                $cmp = strcmp((string) ($a['recorded_date'] ?? ''), (string) ($b['recorded_date'] ?? ''));
            } elseif ($orderKey === 'brand') {
                $cmp = strnatcasecmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
            } elseif ($orderKey === 'sell') {
                $cmp = (float) ($a['sell_price'] ?? 0) <=> (float) ($b['sell_price'] ?? 0);
            } elseif ($orderKey === 'buyback') {
                $aPrice = $a['buyback_price'] ?? null;
                $bPrice = $b['buyback_price'] ?? null;
                $cmp = $aPrice === null && $bPrice === null ? 0 : ($aPrice === null ? -1 : ($bPrice === null ? 1 : (float) $aPrice <=> (float) $bPrice));
            }

            if ($cmp !== 0) {
                return $flip * $cmp;
            }

            if ($orderKey !== 'date') {
                $cmp = strcmp((string) ($b['recorded_date'] ?? ''), (string) ($a['recorded_date'] ?? ''));

                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            if ($orderKey !== 'brand') {
                return strnatcasecmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
            }

            return 0;
        });

        return $rows;
    }

    /**
     * All rows matching a filter, newest first, with no paging:
     * the input for the official-preference pass, which must see
     * every candidate row for a brand+date before it can pick one.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string, search?: string} $filters
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
     * How many rows the same filter matches - the History tab's
     * page count. An empty $provider means "all providers".
     * Counts table rows, so a row cleanRows() would
     * drop (no symbol, no positive price) is still counted; the
     * sync only ever inserts valid rows, so the count matches
     * what the table shows. With $official given (and no
     * provider filter) it counts the preferred rows, so the
     * page count matches the deduped table.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string, search?: string} $filters
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
     * The latest stored sell price per symbol on or before a date.
     * Rows are newest first with the official provider preferred
     * per brand+date, so the value is the same previous close the
     * History chart plots. A symbol with no stored row before the
     * date is left out.
     *
     * @param array<int, string> $symbols
     * @param array<string, string> $official
     * @return array<string, float>
     */
    public static function previousSellPrices(array $symbols, string $beforeDate, array $official = []): array
    {
        if ($symbols === []) {
            return [];
        }

        $rows = self::historyFiltered('', ['symbols' => $symbols, 'to' => $beforeDate], 200, 0, $official);
        $previous = [];

        foreach ($rows as $row) {
            if (!isset($previous[$row['symbol']])) {
                $previous[$row['symbol']] = $row['sell_price'];
            }
        }

        return $previous;
    }

    /**
     * The shared WHERE clause for the filtered history queries:
     * provider first (omitted when empty - "all providers"),
     * then the optional symbol and date filters.
     *
     * @param array{symbol?: string, symbols?: array<int, string>, from?: string, to?: string} $filters
     * @return array{string, array<int, string|int>}
     */
    private static function historyWhere(string $provider, array $filters): array
    {
        global $wpdb;

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

        $search = (string) ($filters['search'] ?? '');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (symbol LIKE %s OR provider LIKE %s OR recorded_date LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
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
     * Stream rows for the CSV Incremental Export, oldest first, in
     * chunks: the generator fetches one chunk at a time and yields
     * one row at a time, so an export of the full history never
     * holds more than a chunk in memory. Both export modes (date
     * range and export-all) read through here; the only difference
     * is the WHERE clause. A from/to pair that is not a real Y-m-d
     * date is ignored (historyDate), so an empty pair means "all
     * rows" and a hand-made URL can never break the query.
     *
     * @return iterable<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int}>
     */
    public static function streamExportRows(string $from, string $to, int $chunkSize = 500): iterable
    {
        global $wpdb;

        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        [$where, $params] = self::historyWhere('', ['from' => $from, 'to' => $to]);

        $chunk = max(1, $chunkSize);
        $offset = 0;

        while (true) {
            $pageParams = array_merge($params, [$chunk, $offset]);

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT provider, symbol, sell_price, buyback_price, currency, recorded_date, fetched_at
                     FROM {$table} {$where}
                     ORDER BY recorded_date ASC, symbol ASC, id ASC LIMIT %d OFFSET %d",
                    ...$pageParams
                ),
                ARRAY_A
            );

            if (!is_array($rows) || $rows === []) {
                return;
            }

            foreach (self::cleanRows($rows) as $row) {
                yield $row;
            }

            if (count($rows) < $chunk) {
                return;
            }

            $offset += $chunk;
        }
    }

    /**
     * A real Y-m-d date, or '' - anything else (garbage, a
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
     * yet or holds no rows - never an error.
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

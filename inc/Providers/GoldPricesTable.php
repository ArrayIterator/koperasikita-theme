<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Custom table for fetched gold prices (`{prefix}kk_gold_prices`).
 *
 * The Logam Mulia API provider stores every price it fetches here —
 * one row per provider + symbol — instead of an option, so the
 * last-good price survives with its real timestamp and the stored
 * rows (sell + buyback) can be inspected. The table is created
 * lazily on the first write (dbDelta, guarded by a version
 * option): the theme has no activation hook that would run on the
 * already-live site, so the first successful fetch creates it.
 *
 * Columns: id, provider, symbol, sell_price, buyback_price,
 * currency, recorded_date (the upstream's date for the price),
 * fetched_at (Unix timestamp of the fetch). Unique key on
 * provider + symbol, so a re-fetch replaces the row.
 */
final class GoldPricesTable
{
    public const VERSION = '1';

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
            UNIQUE KEY provider_symbol (provider, symbol)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /**
     * Store (or replace) one fetched price row. Returns false when
     * the write fails; a failed write must never break the price
     * response, so callers ignore the return.
     */
    public static function upsert(
        string $provider,
        string $symbol,
        float $sell,
        ?float $buyback,
        string $currency,
        ?string $recordedDate,
        int $fetchedAt
    ): bool {
        self::maybeCreate();

        global $wpdb;

        $result = $wpdb->replace(
            self::table(),
            [
                'provider' => $provider,
                'symbol' => $symbol,
                'sell_price' => $sell,
                'buyback_price' => $buyback,
                'currency' => $currency,
                'recorded_date' => $recordedDate,
                'fetched_at' => $fetchedAt,
            ],
            ['%s', '%s', '%f', '%f', '%s', '%s', '%d']
        );

        return $result !== false;
    }

    /**
     * Last-good rows for one provider, keyed by symbol. An empty
     * array means the table does not exist yet or holds no rows —
     * never an error.
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
                "SELECT symbol, sell_price, buyback_price, currency, recorded_date, fetched_at FROM {$table} WHERE provider = %s",
                $provider
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $good = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $symbol = (string) ($row['symbol'] ?? '');
            $sell = (float) ($row['sell_price'] ?? 0);

            if ($symbol === '' || $sell <= 0) {
                continue;
            }

            $good[$symbol] = [
                'sell_price' => $sell,
                'buyback_price' => isset($row['buyback_price']) && $row['buyback_price'] !== null
                    ? (float) $row['buyback_price']
                    : null,
                'currency' => (string) ($row['currency'] ?? 'IDR'),
                'recorded_date' => isset($row['recorded_date']) && $row['recorded_date'] !== null
                    ? (string) $row['recorded_date']
                    : null,
                'fetched_at' => (int) ($row['fetched_at'] ?? 0) > 0
                    ? (int) $row['fetched_at']
                    : time(),
            ];
        }

        return $good;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Local subscriber record (`{prefix}kk_subscribers`).
 *
 * The [kk_subscribe] form stores every valid address here first, so
 * the site keeps its own subscriber list even when MailPoet is not
 * installed. MailPoet, when active, is still synced through its own
 * API - this table is the theme's record, not a replacement for it.
 *
 * One row per email address - the UNIQUE key on email means a
 * re-submit can never create a duplicate. record() is an upsert:
 * the first submit inserts, a later submit for the same address
 * only refreshes status, source and updated_at, so resubscribing
 * is idempotent and never leaks whether the address was already
 * stored.
 *
 * The table is created lazily on the first write (dbDelta, guarded
 * by a version option), mirroring GoldPricesTable: the theme has
 * no activation hook that would run on the already-live site, so
 * the first successful subscribe creates it. Reads against a
 * missing table return empty results instead of erroring.
 *
 * Columns: id, email, status (subscribed/pending/unsubscribed),
 * source (local/mailpoet), created_at, updated_at.
 */
final class Subscribers
{
    public const VERSION = '1';

    public const VERSION_OPTION = 'kk_subscribers_table_version';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_MAILPOET = 'mailpoet';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kk_subscribers';
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
            email varchar(254) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'subscribed',
            source varchar(20) NOT NULL DEFAULT 'local',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function tableExists(): bool
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Store one address, or refresh the existing row for it. Returns
     * false only when the write itself failed - a duplicate address
     * is a success, not an error.
     */
    public static function record(
        string $email,
        string $source = self::SOURCE_LOCAL,
        string $status = self::STATUS_SUBSCRIBED
    ): bool {
        $email = sanitize_email($email);

        if ($email === '' || !is_email($email)) {
            return false;
        }

        if (!in_array($status, [self::STATUS_SUBSCRIBED, self::STATUS_PENDING, self::STATUS_UNSUBSCRIBED], true)) {
            $status = self::STATUS_SUBSCRIBED;
        }

        if (!in_array($source, [self::SOURCE_LOCAL, self::SOURCE_MAILPOET], true)) {
            $source = self::SOURCE_LOCAL;
        }

        self::maybeCreate();

        global $wpdb;

        $table = self::table();
        $now = current_time('mysql');

        // Upsert on the email UNIQUE key: a re-submit refreshes
        // status, source and updated_at but keeps the original
        // created_at and id, so the same address is always one row.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (email, status, source, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), updated_at = VALUES(updated_at)",
            $email,
            $status,
            $source,
            $now,
            $now
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * One page of subscribers, newest first.
     *
     * @param array{search?: string, page?: int, per_page?: int} $args
     * @return array{rows: array<int, array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string}>, total: int}
     */
    public static function list(array $args = []): array
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return ['rows' => [], 'total' => 0];
        }

        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];

        if ($search !== '') {
            $where = ' WHERE email LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if ($params === []) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, email, status, source, created_at, updated_at
                     FROM {$table}
                     ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                    $perPage,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table}{$where}", $params)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, email, status, source, created_at, updated_at
                     FROM {$table}{$where}
                     ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [$perPage, $offset])
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows)) {
            return ['rows' => [], 'total' => $total];
        }

        return ['rows' => self::cleanRows($rows), 'total' => $total];
    }

    public static function count(string $search = ''): int
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return 0;
        }

        $search = trim($search);

        if ($search === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE email LIKE %s",
                '%' . $wpdb->esc_like($search) . '%'
            )
        );
    }

    public static function delete(int $id): bool
    {
        global $wpdb;

        if ($id <= 0 || !self::tableExists()) {
            return false;
        }

        $deleted = $wpdb->delete(self::table(), ['id' => $id], ['%d']);

        return $deleted !== false && $deleted > 0;
    }

    /**
     * Change one subscriber's source (where the address actually
     * landed). Returns false when the address has no row or the
     * write failed - so a failed MailPoet sync can never flip a
     * row to 'mailpoet' by accident.
     */
    public static function setSource(string $email, string $source): bool
    {
        global $wpdb;

        $email = sanitize_email($email);

        if ($email === '' || !is_email($email) || !self::tableExists()) {
            return false;
        }

        if (!in_array($source, [self::SOURCE_LOCAL, self::SOURCE_MAILPOET], true)) {
            return false;
        }

        $updated = $wpdb->update(
            self::table(),
            ['source' => $source, 'updated_at' => current_time('mysql')],
            ['email' => $email],
            ['%s', '%s'],
            ['%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Every subscriber, newest first, for the CSV export. Capped so
     * a very large list cannot exhaust memory.
     *
     * @return array<int, array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string}>
     */
    public static function exportRows(): array
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT id, email, status, source, created_at, updated_at
             FROM {$table}
             ORDER BY created_at DESC, id DESC LIMIT 10000",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return self::cleanRows($rows);
    }

    /**
     * Normalise raw DB rows into typed arrays, dropping rows with
     * no usable email.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string}>
     */
    private static function cleanRows(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = (string) ($row['email'] ?? '');

            if ($email === '' || !is_email($email)) {
                continue;
            }

            $clean[] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
                'status' => (string) ($row['status'] ?? self::STATUS_SUBSCRIBED),
                'source' => (string) ($row['source'] ?? self::SOURCE_LOCAL),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $clean;
    }
}

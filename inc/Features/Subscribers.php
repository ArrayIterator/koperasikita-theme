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
 * The table is created lazily (dbDelta, guarded by a version
 * option), mirroring GoldPricesTable: the theme has no activation
 * hook that would run on the already-live site. The upgrade is
 * ensured wherever the table is read, not only on the first
 * write: the admin sync and the Subscribers page call
 * maybeCreate() first, so a version bump ships its columns
 * without waiting for a new signup. The version option alone
 * is never trusted - a failed ALTER would leave the option
 * ahead of the table, so the columns are verified against the
 * table itself before the option is written or believed.
 *
 * Columns: id, email, first_name, last_name, subscribed_ip,
 * confirmed_ip, status (subscribed/pending/unsubscribed), source
 * (local/mailpoet), created_at, updated_at.
 */
final class Subscribers
{
    public const VERSION = '3';

    public const VERSION_OPTION = 'kk_subscribers_table_version';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_MAILPOET = 'mailpoet';

    /**
     * Test addresses the MailPoet sync never touches, in either
     * direction: never pushed, never pulled, never counted as
     * pending or failed. Hardcoded on purpose - these are test
     * addresses, not a user setting.
     */
    public const IGNORED_SYNC_EMAIL = 'test-newsletter@example.com';

    /**
     * Local part the MailPoet sync never touches, on any domain
     * (test-subscriber@example.com, test-subscriber@koperasikita.com,
     * ...). Same rule as IGNORED_SYNC_EMAIL.
     */
    public const IGNORED_SYNC_LOCAL_PART = 'test-subscriber';

    /**
     * Whether an address is on the sync ignore list: the exact
     * test address, or any address with the test local part.
     * Compared case-insensitively; an invalid address is never
     * ignored here (it is rejected by the caller instead).
     */
    public static function isIgnoredSyncAddress(string $email): bool
    {
        $email = strtolower(sanitize_email($email));

        if ($email === '' || !is_email($email)) {
            return false;
        }

        if ($email === self::IGNORED_SYNC_EMAIL) {
            return true;
        }

        $at = strpos($email, '@');

        return $at !== false && substr($email, 0, $at) === self::IGNORED_SYNC_LOCAL_PART;
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kk_subscribers';
    }

    /**
     * Ensure the table carries the current columns, creating or
     * upgrading it when it does not. Returns true only when the
     * columns are verified present afterwards: a dbDelta that
     * silently fails to add a column must not mark the upgrade
     * done, or every later read would fail the same quiet way
     * (a SELECT naming a missing column returns nothing, so a
     * sync would report 0 pushed with the row still pending).
     * The version option is written only after the columns are
     * confirmed, and it is believed only after the same check,
     * so a half-applied upgrade retries on the next call instead
     * of being trusted forever.
     */
    public static function maybeCreate(): bool
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION && self::hasCurrentColumns()) {
            return true;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(254) NOT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            subscribed_ip varchar(45) NOT NULL DEFAULT '',
            confirmed_ip varchar(45) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'subscribed',
            source varchar(20) NOT NULL DEFAULT 'local',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) {$charset};";

        dbDelta($sql);

        if (!self::hasCurrentColumns()) {
            return false;
        }

        update_option(self::VERSION_OPTION, self::VERSION, false);

        return true;
    }

    /**
     * Whether the subscribers table carries every current
     * column. A missing table reads as not current; a SELECT
     * naming a column that is not there fails the same way,
     * so this is the check the upgrade is judged by.
     */
    private static function hasCurrentColumns(): bool
    {
        global $wpdb;

        if (!self::tableExists()) {
            return false;
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM " . self::table(), ARRAY_A);

        if (!is_array($columns)) {
            return false;
        }

        $names = [];

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['Field'])) {
                $names[] = (string) $column['Field'];
            }
        }

        foreach (['id', 'email', 'first_name', 'last_name', 'subscribed_ip', 'confirmed_ip', 'status', 'source', 'created_at', 'updated_at'] as $required) {
            if (!in_array($required, $names, true)) {
                return false;
            }
        }

        return true;
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
     * is a success, not an error. Names are optional: a re-submit
     * that leaves them empty keeps the stored names, so a later
     * submit without names can never wipe them. The IPs are audit
     * data: the first non-empty value wins, so a re-submit from
     * another address can never rewrite the signup or the
     * confirmation address.
     */
    public static function record(
        string $email,
        string $source = self::SOURCE_LOCAL,
        string $status = self::STATUS_SUBSCRIBED,
        string $firstName = '',
        string $lastName = '',
        string $subscribedIp = '',
        string $confirmedIp = ''
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

        $firstName = self::cleanName($firstName);
        $lastName = self::cleanName($lastName);
        $subscribedIp = self::cleanIp($subscribedIp);
        $confirmedIp = self::cleanIp($confirmedIp);

        self::maybeCreate();

        global $wpdb;

        $table = self::table();
        $now = current_time('mysql');

        // Upsert on the email UNIQUE key: a re-submit refreshes
        // status, source and updated_at but keeps the original
        // created_at and id, so the same address is always one row.
        // Names only overwrite when given: an empty name means
        // "not provided", not "clear the stored name". The IPs
        // only fill when the stored value is empty: the first
        // signup or confirmation address is the audit fact.
        $update = 'status = VALUES(status), source = VALUES(source), updated_at = VALUES(updated_at)'
            . ", subscribed_ip = IF(subscribed_ip = '', VALUES(subscribed_ip), subscribed_ip)"
            . ", confirmed_ip = IF(confirmed_ip = '', VALUES(confirmed_ip), confirmed_ip)";

        if ($firstName !== '') {
            $update .= ', first_name = VALUES(first_name)';
        }

        if ($lastName !== '') {
            $update .= ', last_name = VALUES(last_name)';
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (email, first_name, last_name, subscribed_ip, confirmed_ip, status, source, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE {$update}",
            $email,
            $firstName,
            $lastName,
            $subscribedIp,
            $confirmedIp,
            $status,
            $source,
            $now,
            $now
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * Fill the audit IPs of an existing row, but only where the
     * row has none yet: the first signup or confirmation address
     * is never rewritten. Used by the MailPoet pull, which reads
     * the IPs off the MailPoet record. Returns true when at least
     * one address was filled.
     */
    public static function backfillIps(string $email, string $subscribedIp = '', string $confirmedIp = ''): bool
    {
        global $wpdb;

        $email = sanitize_email($email);
        $subscribedIp = self::cleanIp($subscribedIp);
        $confirmedIp = self::cleanIp($confirmedIp);

        if ($email === '' || !is_email($email) || !self::tableExists()) {
            return false;
        }

        $filled = false;

        if ($subscribedIp !== '') {
            $updated = $wpdb->update(
                self::table(),
                ['subscribed_ip' => $subscribedIp, 'updated_at' => current_time('mysql')],
                ['email' => $email, 'subscribed_ip' => ''],
                ['%s', '%s'],
                ['%s', '%s']
            );

            $filled = $filled || ($updated !== false && $updated > 0);
        }

        if ($confirmedIp !== '') {
            $updated = $wpdb->update(
                self::table(),
                ['confirmed_ip' => $confirmedIp, 'updated_at' => current_time('mysql')],
                ['email' => $email, 'confirmed_ip' => ''],
                ['%s', '%s'],
                ['%s', '%s']
            );

            $filled = $filled || ($updated !== false && $updated > 0);
        }

        return $filled;
    }

    /**
     * One name for storage: plain text, trimmed, capped at the
     * column width. Anything longer is cut, never rejected - a
     * name must never fail a signup.
     */
    private static function cleanName(string $name): string
    {
        $name = trim(sanitize_text_field($name));

        if (strlen($name) > 100) {
            $name = substr($name, 0, 100);
        }

        return $name;
    }

    /**
     * One IP address for storage: only a valid IPv4 or IPv6
     * address is stored, anything else reads as empty. The
     * caller passes a single address, never a header dump.
     */
    private static function cleanIp(string $ip): string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * One page of subscribers, newest first.
     *
     * @param array{search?: string, page?: int, per_page?: int} $args
     * @return array{rows: array<int, array{id: int, email: string, first_name: string, last_name: string, subscribed_ip: string, confirmed_ip: string, status: string, source: string, created_at: string, updated_at: string}>, total: int}
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

        // Sort: whitelisted column + direction only, so the order
        // clause is built from literals, never from raw input.
        $orderBy = isset($args['orderby']) ? (string) $args['orderby'] : 'date';
        $orderColumns = ['email' => 'email', 'date' => 'created_at', 'status' => 'status', 'source' => 'source'];
        $orderColumn = $orderColumns[$orderBy] ?? 'created_at';
        $orderDirection = isset($args['order']) && strtolower((string) $args['order']) === 'asc' ? 'ASC' : 'DESC';
        $orderSql = $orderColumn . ' ' . $orderDirection . ', id DESC';

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
                    "SELECT id, email, first_name, last_name, subscribed_ip, confirmed_ip, status, source, created_at, updated_at
                     FROM {$table}
                     ORDER BY {$orderSql} LIMIT %d OFFSET %d",
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
                    "SELECT id, email, first_name, last_name, subscribed_ip, confirmed_ip, status, source, created_at, updated_at
                     FROM {$table}{$where}
                     ORDER BY {$orderSql} LIMIT %d OFFSET %d",
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
     * Whether an address already has a local row, in any status.
     * The MailPoet pull inserts only addresses that are not
     * stored here yet, so a local row - including an
     * unsubscribed one - is never overwritten by a pull.
     */
    public static function exists(string $email): bool
    {
        global $wpdb;

        $email = sanitize_email($email);

        if ($email === '' || !is_email($email) || !self::tableExists()) {
            return false;
        }

        $table = self::table();

        return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email)) !== null;
    }

    /**
     * How many subscribed rows are stored here but not in MailPoet
     * yet (source 'local'). The admin sync button only shows when
     * this is above zero. Ignored test addresses never count.
     */
    public static function countLocalPending(): int
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // The sync ignore list (see isIgnoredSyncAddress):
                // test addresses never count as pending.
                "SELECT COUNT(*) FROM {$table} WHERE status = %s AND source = %s AND LOWER(email) <> %s AND LOWER(SUBSTRING_INDEX(email, '@', 1)) <> %s",
                self::STATUS_SUBSCRIBED,
                self::SOURCE_LOCAL,
                self::IGNORED_SYNC_EMAIL,
                self::IGNORED_SYNC_LOCAL_PART
            )
        );
    }

    /**
     * Subscribed addresses stored here but not in MailPoet yet,
     * oldest first, capped so one sync click cannot run forever.
     *
     * @return array<int, string>
     */
    public static function localSubscribedEmails(int $limit): array
    {
        return array_column(self::localSubscribedRows($limit), 'email');
    }

    /**
     * The same batch as localSubscribedEmails, with the stored
     * first and last name and the audit IPs per address, so the
     * push carries the names and the signup address into
     * MailPoet.
     *
     * @return array<int, array{email: string, first_name: string, last_name: string, subscribed_ip: string, confirmed_ip: string}>
     */
    public static function localSubscribedRows(int $limit): array
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // The sync ignore list (see isIgnoredSyncAddress):
                // test addresses never enter a sync batch.
                "SELECT email, first_name, last_name, subscribed_ip, confirmed_ip FROM {$table} WHERE status = %s AND source = %s AND LOWER(email) <> %s AND LOWER(SUBSTRING_INDEX(email, '@', 1)) <> %s ORDER BY created_at ASC, id ASC LIMIT %d",
                self::STATUS_SUBSCRIBED,
                self::SOURCE_LOCAL,
                self::IGNORED_SYNC_EMAIL,
                self::IGNORED_SYNC_LOCAL_PART,
                max(1, $limit)
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $batch = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = sanitize_email((string) ($row['email'] ?? ''));

            if ($email === '' || !is_email($email)) {
                continue;
            }

            $batch[] = [
                'email' => $email,
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'subscribed_ip' => (string) ($row['subscribed_ip'] ?? ''),
                'confirmed_ip' => (string) ($row['confirmed_ip'] ?? ''),
            ];
        }

        return $batch;
    }

    /**
     * Change one subscriber's source (where the address actually
     * landed). Returns false when the address has no row or the
     * write failed - so a failed MailPoet sync can never flip a
     * row to 'mailpoet' by accident. Already holding the target
     * source counts as a success: a second click (or a
     * concurrent one) that finds the row already flipped must
     * never report a failure for a row that is already where it
     * belongs - $wpdb->update reports 0 rows changed when the
     * values written are the values already stored.
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

        if ($updated === false) {
            return false;
        }

        if ($updated > 0) {
            return true;
        }

        // Nothing changed: the row may already hold the target
        // source (a concurrent click flipped it first). Read the
        // actual source back instead of trusting the row count.
        return self::sourceOf($email) === $source;
    }

    /**
     * One subscriber's current source, or null when the address
     * has no row. Read straight off the table for the setSource
     * re-check.
     */
    private static function sourceOf(string $email): ?string
    {
        global $wpdb;

        $source = $wpdb->get_var($wpdb->prepare(
            'SELECT source FROM ' . self::table() . ' WHERE email = %s',
            $email
        ));

        return is_string($source) ? $source : null;
    }

    /**
     * Every subscriber, newest first, for the CSV export. Capped so
     * a very large list cannot exhaust memory.
     *
     * @return array<int, array{id: int, email: string, first_name: string, last_name: string, subscribed_ip: string, confirmed_ip: string, status: string, source: string, created_at: string, updated_at: string}>
     */
    public static function exportRows(): array
    {
        global $wpdb;

        $table = self::table();

        if (!self::tableExists()) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT id, email, first_name, last_name, subscribed_ip, confirmed_ip, status, source, created_at, updated_at
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
     * @return array<int, array{id: int, email: string, first_name: string, last_name: string, subscribed_ip: string, confirmed_ip: string, status: string, source: string, created_at: string, updated_at: string}>
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
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'subscribed_ip' => (string) ($row['subscribed_ip'] ?? ''),
                'confirmed_ip' => (string) ($row['confirmed_ip'] ?? ''),
                'status' => (string) ($row['status'] ?? self::STATUS_SUBSCRIBED),
                'source' => (string) ($row['source'] ?? self::SOURCE_LOCAL),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $clean;
    }
}

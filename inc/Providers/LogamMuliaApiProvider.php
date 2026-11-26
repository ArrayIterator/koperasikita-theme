<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Features\Markets;

/**
 * Live gold provider (Logam Mulia API, free, no API key).
 *
 * Prices come from the upstream's backup CSV files
 * (https://raw.githubusercontent.com/iamutaki/logam-mulia-api/main/backup/prices/{source}.csv),
 * NOT the JSON API — the CSV is the source the user asked for.
 * Three files, one per source (never all 19 upstream sources):
 *  - galeri24.csv (~1.5 MB of append-only history): 1-gram
 *    Galeri 24 + UBS sell/buyback prices.
 *  - hargaemas-net.csv: 1-gram Antam sell/buyback price. It is the
 *    Antam fallback: galeri24.csv's ANTAM section is the primary
 *    Antam source (per the materialType mapping), but it is 0 for
 *    1 gram at the latest date, so the Antam row comes from here
 *    until Galeri 24's ANTAM section reports a positive price.
 *  - pegadaian.csv: Pegadaian 0.01-gram price, scaled x100 to 1 gram.
 *
 * Each file is append-only history; only rows at the most recent
 * recordedDates count, with weight 1 (0.01 for Pegadaian), material
 * gold, and a positive sell price — zero prices are skipped.
 * Logam Mulia's CSV is stale (latest 2026-10-23, its Jina key is
 * dead), so it is not used. EmasKita is not in the upstream at
 * all and stays manual. Spot is NOT supplied: none of these files
 * is a spot price (they are retail brand prices); spot stays
 * with Xaus.
 *
 * SYNC MODEL (the upstream updates once a day; the sync runs on a
 * schedule the admin picks — see the sync settings on the Gold
 * Prices page — never on the live 15-minute cadence):
 *  - The hot path (prices()) reads the 15-minute transient, then
 *    the custom table — it never fetches a CSV.
 *  - The sync runs on WP-Cron and on demand (admin page, AJAX),
 *    ONE SOURCE AT A TIME. Each step fetches one file, checks
 *    every row against the table by (provider, symbol,
 *    recordedDate), and inserts only the rows that are not there
 *    yet (INSERT IGNORE + the UNIQUE key, so a re-run can never
 *    duplicate). Each run imports the most recent N days (the
 *    batch size setting, default 7), oldest first, so a fresh site
 *    gets a week of chart history on its first sync. A coarse
 *    fast-path reads MAX(recordedDate) first and skips a file
 *    whose max date is strictly older than the table's; the
 *    per-row check is the guarantee and heals gaps (a missed day
 *    is picked up next run because that exact date is absent).
 *    Each step commits only its own source's rows, so a request
 *    that dies mid-way leaves no half-synced state.
 *  - Every fetched price is stored in the kk_gold_prices custom
 *    table (GoldPricesTable), which is also the last-good fallback
 *    when the upstream is unreachable — so the widget keeps showing
 *    the last real price with its real timestamp, never an invented
 *    one.
 *
 * MarketPrice carries only the sell price (it has no buyback
 * field); the buyback price is stored in the table alongside.
 */
final class LogamMuliaApiProvider extends AbstractLiveProvider
{
    public const CSV_BASE = 'https://raw.githubusercontent.com/iamutaki/logam-mulia-api/main/backup/prices';

    /** WP-Cron hook for the daily sync. */
    public const CRON_HOOK = 'kk_logam_mulia_daily_sync';

    /** AJAX action for one manual sync step (one source). */
    public const SYNC_ACTION = 'kk_logam_mulia_sync';

    /** Option holding the last sync status (per source + global). */
    public const STATUS_OPTION = 'kk_gold_logam_mulia_status';

    /** Option holding the automatic sync settings (see syncSettings()). */
    public const SYNC_SETTINGS_OPTION = 'kk_gold_logam_mulia_sync';

    /** Hours between automatic sync runs the admin may pick. */
    public const INTERVAL_HOURS = [1, 2, 3, 4, 6, 8, 12, 24];

    /** Provider id (also the value stored in the prices table). */
    public const PROVIDER_ID = 'logam-mulia-api';

    /** Sources synced, in step order. */
    public const SOURCES = ['galeri24', 'pegadaian', 'hargaemas-net'];

    /** Consecutive failures before a source drops to lowest priority. */
    private const DEPRIORITISE_AFTER = 3;

    /** Retry delay after an ordinary failure (next daily run). */
    private const RETRY_DAILY_SECONDS = 86400;

    /** Retry delay once deprioritised (once a week). */
    private const RETRY_DEPRIORITISED_SECONDS = 604800;

    public function id(): string
    {
        return self::PROVIDER_ID;
    }

    public function label(): string
    {
        return 'Logam Mulia API (live Antam, Galeri 24, UBS, Pegadaian, Lotus Archi)';
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

        foreach (['antam', 'ubs', 'galeri-24', 'pegadaian', 'lotus-archi'] as $symbol) {
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
     * Prices from the synced table — never a live CSV fetch. The
     * hot path is the 15-minute transient, then the last-good rows
     * from kk_gold_prices (with their real fetched timestamps).
     * The CSV sync (daily cron / manual) is what fills the table.
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

        // 2. Last-good rows from the table.
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

            set_transient($this->transientKey(), $map, $this->cacheSeconds());

            return $this->toMarketPrices($market, $map);
        }

        // 3. No data at all (never synced, or the table is empty).
        return [];
    }

    /**
     * The live market fetch (all three CSVs, merged). Kept to
     * satisfy the abstract contract; the sync uses fetchSource()
     * per source instead, so one step never handles more than one
     * file. Not called by prices().
     *
     * @return array<string, array{price: float, change: float|null, updated: int, buyback: float|null, recorded_date: string|null}>|null
     */
    protected function fetch(string $market): ?array
    {
        $now = time();
        $merged = [];

        foreach (self::SOURCES as $source) {
            $byDate = $this->fetchSource($source, $now, 1);

            if ($byDate === []) {
                continue;
            }

            // Latest date only, like before the batch change.
            $latest = end($byDate);

            if (is_array($latest)) {
                foreach ($latest as $symbol => $entry) {
                    $merged[$symbol] = $entry;
                }
            }
        }

        return $merged !== [] ? $merged : null;
    }

    /**
     * Sync ONE source (one step). Fetches that source's CSV, keeps
     * the most recent N dates (the batch size setting), and inserts
     * only the rows whose (provider, symbol, recorded_date) is not
     * in the table yet. Returns the step result (inserted vs
     * skipped, latest date, error). A failed fetch inserts nothing
     * and records the failure in the backlog (never a tight retry
     * loop); a successful fetch clears the source's backlog entry.
     *
     * @return array{source: string, inserted: int, skipped: int, latest_date: string|null, error: string|null, deferred: bool}
     */
    public function syncSource(string $source): array
    {
        $source = sanitize_key($source);

        if (!in_array($source, self::SOURCES, true)) {
            return [
                'source' => $source,
                'inserted' => 0,
                'skipped' => 0,
                'latest_date' => null,
                'error' => __('Unknown source.', 'koperasikita'),
                'deferred' => false,
            ];
        }

        $now = time();
        $result = [
            'source' => $source,
            'inserted' => 0,
            'skipped' => 0,
            'latest_date' => null,
            'error' => null,
            'deferred' => false,
        ];

        // One run imports the most recent N days (the batch size
        // setting), oldest first, grouped by date.
        $byDate = $this->fetchSource($source, $now, self::syncSettings()['batch_days']);

        if ($byDate === []) {
            // Distinguish "fetch failed" from "file has no usable
            // rows": fetchSource returns [] for both, so re-check
            // the raw fetch to name the error honestly.
            $csv = $this->getCsv(self::CSV_BASE . '/' . $source . '.csv');
            $result['error'] = $csv === null
                ? __('Could not download the price file.', 'koperasikita')
                : __('The price file has no usable 1-gram rows.', 'koperasikita');
            $this->recordFailure($source, (string) $result['error'], $now);
            $this->recordStep($source, $result, $now);

            return $result;
        }

        // The fetch succeeded, so the source is healthy again —
        // clear any backlog entry (a later failure starts a fresh
        // consecutive count).
        $this->clearFailure($source);

        // Coarse fast-path: the file's max date is the newest date
        // in the batch. When it is strictly older than the table's
        // max, the file holds nothing new — skip it without touching
        // the table. When it is equal or newer, the per-row check
        // below decides (it also heals gaps at those dates).
        $fileMax = null;
        $totalEntries = 0;

        foreach ($byDate as $date => $entries) {
            if ($date !== '' && ($fileMax === null || $date > $fileMax)) {
                $fileMax = $date;
            }

            $totalEntries += count($entries);
        }

        $result['latest_date'] = $fileMax;

        $tableMax = GoldPricesTable::maxRecordedDate($this->id());
        if ($fileMax !== null && $tableMax !== null && $fileMax < $tableMax) {
            $result['skipped'] = $totalEntries;
            $this->recordStep($source, $result, $now);

            return $result;
        }

        // Per-row check-then-insert, oldest date first: only rows
        // whose exact (provider, symbol, recorded_date) is absent
        // are inserted. INSERT IGNORE + the UNIQUE key make a
        // re-run idempotent.
        $inserted = 0;
        $skipped = 0;

        foreach ($byDate as $entries) {
            foreach ($entries as $symbol => $entry) {
                $date = (string) ($entry['recorded_date'] ?? '');
                if ($date === '') {
                    $skipped++;
                    continue;
                }

                $n = GoldPricesTable::insertIgnore(
                    $this->id(),
                    $symbol,
                    (float) $entry['price'],
                    isset($entry['buyback']) && is_numeric($entry['buyback'])
                        ? (float) $entry['buyback']
                        : null,
                    'IDR',
                    $date,
                    (int) ($entry['updated'] ?? $now)
                );

                if ($n > 0) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        }

        $result['inserted'] = $inserted;
        $result['skipped'] = $skipped;

        // New rows landed: drop the transient so the next read
        // picks the fresh prices up from the table.
        if ($inserted > 0) {
            delete_transient($this->transientKey());
        }

        $this->recordStep($source, $result, $now);

        return $result;
    }

    /**
     * Sync ALL sources, one at a time, each step isolated: a source
     * that fails does not stop the others. Used by the daily cron.
     * A source that failed 3+ times is deprioritised and only
     * retried once its backoff has elapsed (once a week) — the
     * daily run skips it instead of hammering the upstream.
     * Manual sync calls syncSource() directly and bypasses the
     * backoff.
     *
     * @return array{inserted: int, skipped: int, sources: array<string, array{source: string, inserted: int, skipped: int, latest_date: string|null, error: string|null, deferred: bool}>}
     */
    public function syncAll(): array
    {
        $now = time();
        $total = ['inserted' => 0, 'skipped' => 0, 'sources' => []];

        foreach (self::SOURCES as $source) {
            if (!$this->isDue($source, $now)) {
                // Deprioritised and its weekly retry is not due
                // yet: skip it without touching the upstream.
                // The status shows it as deprioritised with its
                // next retry date.
                $total['sources'][$source] = [
                    'source' => $source,
                    'inserted' => 0,
                    'skipped' => 0,
                    'latest_date' => null,
                    'error' => null,
                    'deferred' => true,
                ];
                continue;
            }

            $step = $this->syncSource($source);
            $total['sources'][$source] = $step;
            $total['inserted'] += (int) $step['inserted'];
            $total['skipped'] += (int) $step['skipped'];
        }

        return $total;
    }

    /**
     * Sync status for the admin page / Customizer: last sync time,
     * latest recordedDate in the table, which symbols are present,
     * rows inserted in the last run, and the last error. The
     * per-source entries show what is pending, including the
     * failure backlog (failures, last error, next retry) and
     * whether a source is deprioritised to lowest priority.
     *
     * @return array{synced_at: int|null, latest_date: string|null, symbols: array<int, string>, inserted: int, skipped: int, error: string|null, sources: array<string, array{synced_at: int|null, inserted: int, skipped: int, latest_date: string|null, error: string|null, failures: int, next_retry: int|null, deprioritised: bool}>, backlog: array<string, array{failures: int, last_error: string|null, last_attempt: int|null, next_retry: int|null, deprioritised: bool}>}
     */
    public static function status(): array
    {
        $stored = get_option(self::STATUS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $provider = new self();
        $last = GoldPricesTable::lastGood($provider->id());

        $latestDate = null;
        foreach ($last as $row) {
            $date = (string) ($row['recorded_date'] ?? '');
            if ($date !== '' && ($latestDate === null || $date > $latestDate)) {
                $latestDate = $date;
            }
        }

        $backlog = isset($stored['backlog']) && is_array($stored['backlog']) ? $stored['backlog'] : [];

        $sources = [];
        foreach (self::SOURCES as $source) {
            $s = isset($stored['sources'][$source]) && is_array($stored['sources'][$source])
                ? $stored['sources'][$source]
                : [];
            $b = isset($backlog[$source]) && is_array($backlog[$source]) ? $backlog[$source] : [];
            $failures = (int) ($b['failures'] ?? 0);
            $sources[$source] = [
                'synced_at' => isset($s['synced_at']) ? (int) $s['synced_at'] : null,
                'inserted' => isset($s['inserted']) ? (int) $s['inserted'] : 0,
                'skipped' => isset($s['skipped']) ? (int) $s['skipped'] : 0,
                'latest_date' => isset($s['latest_date']) && is_string($s['latest_date']) ? $s['latest_date'] : null,
                'error' => isset($s['error']) && is_string($s['error']) ? $s['error'] : null,
                'failures' => $failures,
                'next_retry' => isset($b['next_retry']) ? (int) $b['next_retry'] : null,
                'deprioritised' => $failures >= self::DEPRIORITISE_AFTER,
            ];
        }

        $error = null;
        foreach ($sources as $s) {
            if ($s['error'] !== null) {
                $error = $s['error'];
                break;
            }
        }

        $backlogOut = [];
        foreach ($backlog as $source => $b) {
            if (!is_array($b) || !in_array($source, self::SOURCES, true)) {
                continue;
            }
            $failures = (int) ($b['failures'] ?? 0);
            $backlogOut[$source] = [
                'failures' => $failures,
                'last_error' => isset($b['last_error']) && is_string($b['last_error']) ? $b['last_error'] : null,
                'last_attempt' => isset($b['last_attempt']) ? (int) $b['last_attempt'] : null,
                'next_retry' => isset($b['next_retry']) ? (int) $b['next_retry'] : null,
                'deprioritised' => $failures >= self::DEPRIORITISE_AFTER,
            ];
        }

        return [
            'synced_at' => isset($stored['synced_at']) ? (int) $stored['synced_at'] : null,
            'latest_date' => $latestDate,
            'symbols' => array_keys($last),
            'inserted' => isset($stored['inserted']) ? (int) $stored['inserted'] : 0,
            'skipped' => isset($stored['skipped']) ? (int) $stored['skipped'] : 0,
            'error' => $error,
            'sources' => $sources,
            'backlog' => $backlogOut,
        ];
    }

    /**
     * Record one step's result in the status option (last sync
     * time, inserted/skipped counts, per-source entry). The
     * table-derived parts (latest date, symbols) are read live by
     * status(), so they are never stale here.
     *
     * @param array{source: string, inserted: int, skipped: int, latest_date: string|null, error: string|null} $step
     */
    private function recordStep(string $source, array $step, int $now): void
    {
        $stored = get_option(self::STATUS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $sources = isset($stored['sources']) && is_array($stored['sources']) ? $stored['sources'] : [];
        $sources[$source] = [
            'synced_at' => $now,
            'inserted' => (int) $step['inserted'],
            'skipped' => (int) $step['skipped'],
            'latest_date' => $step['latest_date'],
            'error' => $step['error'],
        ];

        update_option(self::STATUS_OPTION, [
            'synced_at' => $now,
            'inserted' => (int) ($stored['inserted'] ?? 0) + (int) $step['inserted'],
            'skipped' => (int) ($stored['skipped'] ?? 0) + (int) $step['skipped'],
            'sources' => $sources,
            // Preserve the failure backlog (recordFailure writes
            // it into the same option).
            'backlog' => isset($stored['backlog']) && is_array($stored['backlog']) ? $stored['backlog'] : [],
        ], false);
    }

    /**
     * Record a failed sync for one source in the backlog: bump
     * its consecutive failure count and set the next retry time.
     * 1-2 failures: retry on the next daily run. 3+ failures:
     * the source drops to lowest priority and is only retried
     * once a week (the daily cron skips it until then). The
     * failure never blocks the other sources and never triggers
     * a tight retry loop.
     */
    private function recordFailure(string $source, string $error, int $now): void
    {
        $stored = get_option(self::STATUS_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $backlog = isset($stored['backlog']) && is_array($stored['backlog']) ? $stored['backlog'] : [];
        $entry = isset($backlog[$source]) && is_array($backlog[$source]) ? $backlog[$source] : [];
        $failures = (int) ($entry['failures'] ?? 0) + 1;
        $deprioritised = $failures >= self::DEPRIORITISE_AFTER;

        $backlog[$source] = [
            'failures' => $failures,
            'last_error' => $error,
            'last_attempt' => $now,
            // Deprioritised: once a week. Otherwise: next daily run.
            'next_retry' => $now + ($deprioritised ? self::RETRY_DEPRIORITISED_SECONDS : self::RETRY_DAILY_SECONDS),
        ];

        $stored['backlog'] = $backlog;
        update_option(self::STATUS_OPTION, $stored, false);
    }

    /**
     * Clear a source's backlog entry after a successful sync.
     * A later failure starts a fresh consecutive count.
     */
    private function clearFailure(string $source): void
    {
        $stored = get_option(self::STATUS_OPTION, []);
        if (!is_array($stored) || !isset($stored['backlog'][$source])) {
            return;
        }

        unset($stored['backlog'][$source]);
        update_option(self::STATUS_OPTION, $stored, false);
    }

    /**
     * Is this source due for a retry now? A source with no
     * backlog entry is always due. A deprioritised source is
     * only due once its weekly backoff has elapsed. (Manual
     * sync calls syncSource() directly and bypasses this.)
     */
    private function isDue(string $source, int $now): bool
    {
        $stored = get_option(self::STATUS_OPTION, []);
        $entry = isset($stored['backlog'][$source]) && is_array($stored['backlog'][$source])
            ? $stored['backlog'][$source]
            : null;

        if ($entry === null) {
            return true;
        }

        $nextRetry = (int) ($entry['next_retry'] ?? 0);

        return $nextRetry <= 0 || $now >= $nextRetry;
    }

    /**
     * WP-Cron callback: sync all sources, one at a time. A source
     * that fails does not stop the others (see syncAll()).
     */
    public static function cronSync(): void
    {
        (new self())->syncAll();
    }

    /**
     * The automatic sync settings, sanitized with the defaults
     * applied. Never saved = the defaults: auto-sync on, once a
     * day, 7 days per run, 8-second fetch timeout.
     *
     * @return array{enabled: bool, interval_hours: int, batch_days: int, timeout: int}
     */
    public static function syncSettings(): array
    {
        $stored = get_option(self::SYNC_SETTINGS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $interval = isset($stored['interval_hours']) ? (int) $stored['interval_hours'] : 24;
        $batch = isset($stored['batch_days']) ? (int) $stored['batch_days'] : 7;
        $timeout = isset($stored['timeout']) ? (int) $stored['timeout'] : 8;

        return [
            'enabled' => !isset($stored['enabled']) || (bool) $stored['enabled'],
            'interval_hours' => in_array($interval, self::INTERVAL_HOURS, true) ? $interval : 24,
            'batch_days' => max(1, min(30, $batch)),
            'timeout' => max(5, min(60, $timeout)),
        ];
    }

    /**
     * Interval choices for the settings form: hours => label.
     *
     * @return array<int, string>
     */
    public static function intervalChoices(): array
    {
        $choices = [];

        foreach (self::INTERVAL_HOURS as $hours) {
            $choices[$hours] = $hours === 1
                ? __('Every hour', 'koperasikita')
                : ($hours === 24
                    ? __('Once a day', 'koperasikita')
                    /* translators: %d: hours between sync runs. */
                    : sprintf(__('Every %d hours', 'koperasikita'), $hours));
        }

        return $choices;
    }

    /**
     * Save the automatic sync settings (from the Gold Prices page),
     * then reschedule the cron to match. Returns the saved settings.
     *
     * @param array<string, mixed> $data
     * @return array{enabled: bool, interval_hours: int, batch_days: int, timeout: int}
     */
    public static function saveSyncSettings(array $data): array
    {
        $settings = [
            'enabled' => !empty($data['enabled']),
            'interval_hours' => isset($data['interval_hours']) ? (int) $data['interval_hours'] : 24,
            'batch_days' => isset($data['batch_days']) ? (int) $data['batch_days'] : 7,
            'timeout' => isset($data['timeout']) ? (int) $data['timeout'] : 8,
        ];

        // Sanitize through the same defaults as the reader, so the
        // stored row is already canonical.
        update_option(self::SYNC_SETTINGS_OPTION, $settings);
        $settings = self::syncSettings();
        self::rescheduleSync();

        return $settings;
    }

    /** WP-Cron schedule name for one interval (hours). */
    public static function scheduleName(int $hours): string
    {
        return 'kk_logam_mulia_' . $hours . 'h';
    }

    /**
     * The cron_schedules callback: one recurrence per interval the
     * admin may pick. Registered in registerSync() on every load,
     * so the names exist both when scheduling and when WP-Cron runs.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addCronSchedules(array $schedules): array
    {
        foreach (self::INTERVAL_HOURS as $hours) {
            $name = self::scheduleName($hours);

            if (isset($schedules[$name])) {
                continue;
            }

            $schedules[$name] = [
                'interval' => $hours * HOUR_IN_SECONDS,
                'display' => $hours === 1
                    ? __('Every hour', 'koperasikita')
                    : ($hours === 24
                        ? __('Once a day', 'koperasikita')
                        /* translators: %d: hours between sync runs. */
                        : sprintf(__('Every %d hours', 'koperasikita'), $hours)),
            ];
        }

        return $schedules;
    }

    /**
     * (Re)schedule the automatic sync to match the saved settings:
     * clear the event when auto-sync is off, schedule it with the
     * chosen interval when it is on. Called on save and from
     * registerSync() on every load, so a changed interval takes
     * effect without waiting for the next run.
     */
    public static function rescheduleSync(): void
    {
        $settings = self::syncSettings();

        if (!$settings['enabled']) {
            wp_clear_scheduled_hook(self::CRON_HOOK);

            return;
        }

        $wanted = self::scheduleName($settings['interval_hours']);
        $event = wp_get_scheduled_event(self::CRON_HOOK);

        if ($event === false || ($event->schedule ?? '') !== $wanted) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time(), $wanted, self::CRON_HOOK);
        }
    }

    /**
     * Register the sync cron + the manual sync AJAX endpoint.
     * Called from Markets::register() (which only runs when the
     * Markets feature is enabled).
     */
    public static function registerSync(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'cronSync']);
        add_filter('cron_schedules', [self::class, 'addCronSchedules']);

        // Reconcile the scheduled event with the saved settings:
        // a changed interval or a disabled auto-sync takes effect
        // on the next page load, not the next run.
        self::rescheduleSync();

        // Manual sync, one source per call (the admin page steps
        // through the sources in order). Capability-gated,
        // nonce-checked by the Ajax contract. No source given means
        // "start from the first source"; the step returns `next`
        // (the following source, or null when done) so the page
        // can chain the calls. Every step syncs its source
        // explicitly — the "next pending" shortcut is not used,
        // because only a fetch can tell whether the CSV has a new
        // date (check-then-insert makes a re-sync harmless).
        Ajax::add(self::SYNC_ACTION, static function (): array {
            $source = isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : '';

            $provider = new self();

            if ($source === '') {
                $source = self::SOURCES[0] ?? '';
            }

            $step = $provider->syncSource($source);
            $step['next'] = $provider->nextSourceAfter($source);

            return $step;
        }, false, 'manage_options');
    }

    /**
     * The source after this one in step order, or null when it is
     * the last. Used by the manual sync AJAX endpoint so the admin
     * page can chain one source per call.
     */
    public function nextSourceAfter(string $source): ?string
    {
        $index = array_search($source, self::SOURCES, true);
        if ($index === false) {
            return self::SOURCES[0] ?? null;
        }

        return self::SOURCES[$index + 1] ?? null;
    }

    /**
     * Fetch + parse + map ONE source's CSV into symbol entries,
     * grouped by recorded date (oldest first). Only the $dates most
     * recent dates count — the batch size setting decides how many
     * days one sync run imports.
     *
     * @return array<string, array<string, array{price: float, change: float|null, updated: int, buyback: float|null, recorded_date: string|null}>>
     */
    private function fetchSource(string $source, int $now, int $dates = 1): array
    {
        $byDate = $this->parseCsv($this->getCsv(self::CSV_BASE . '/' . $source . '.csv'), max(1, $dates));
        $entries = [];

        foreach ($byDate as $date => $rows) {
            $day = [];

            if ($source === 'galeri24') {
                $galeri24 = $this->findRow($rows, 'GALERI 24', 1.0);
                if ($galeri24 !== null) {
                    $day['galeri-24'] = $this->rowToEntry($galeri24, 1.0, $now);
                }

                $ubs = $this->findRow($rows, 'UBS', 1.0);
                if ($ubs !== null) {
                    $day['ubs'] = $this->rowToEntry($ubs, 1.0, $now);
                }

                $antam = $this->findRow($rows, 'ANTAM', 1.0);
                if ($antam !== null) {
                    $day['antam'] = $this->rowToEntry($antam, 1.0, $now);
                }

                // Same source as the scraper's Lotus Archi table: the CSV
                // carries a 1-gram LOTUS ARCHI row at the latest date.
                // findRow matches the material type exactly, so the
                // LOTUS ARCHI GIFT rows never match.
                $lotusArchi = $this->findRow($rows, 'LOTUS ARCHI', 1.0);
                if ($lotusArchi !== null) {
                    $day['lotus-archi'] = $this->rowToEntry($lotusArchi, 1.0, $now);
                }
            } elseif ($source === 'hargaemas-net') {
                $antam = $this->findAntamRow($rows);
                if ($antam !== null) {
                    $day['antam'] = $this->rowToEntry($antam, 1.0, $now);
                }
            } elseif ($source === 'pegadaian') {
                foreach ($rows as $row) {
                    $weight = (float) ($row['weight'] ?? 0);
                    if ($weight <= 0) {
                        continue;
                    }

                    $sell = $row['sellPrice'] ?? null;
                    if (!is_numeric($sell) || (float) $sell <= 0) {
                        continue;
                    }

                    $day['pegadaian'] = $this->rowToEntry($row, 1.0 / $weight, $now);
                    break;
                }
            }

            if ($day !== []) {
                $entries[$date] = $day;
            }
        }

        return $entries;
    }

    /**
     * One CSV fetch (raw.githubusercontent.com), returning the raw
     * text or null on any failure. The timeout comes from the sync
     * settings (default 8 seconds, same as the other live providers)
     * — a fetch that exceeds it fails cleanly instead of hanging
     * (and the failure goes to the backlog, never a tight retry loop).
     */
    private function getCsv(string $url): ?string
    {
        $response = wp_remote_get($url, [
            'timeout' => self::syncSettings()['timeout'],
            'headers' => ['Accept' => 'text/csv'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);

        return $body !== '' ? $body : null;
    }

    /**
     * Parse a CSV payload into rows grouped by recordedDate, oldest
     * first, keeping only the $dates most recent dates. The files
     * are append-only history; only the newest dates count. fgetcsv
     * parses the quoted fields correctly (commas inside materialType
     * are quoted, never split naively). Returns date => rows as
     * header-keyed string arrays.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function parseCsv(?string $csv, int $dates = 1): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }

        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream);
        if (!is_array($header) || $header === []) {
            fclose($stream);
            return [];
        }

        $header = array_map(static fn ($h) => trim((string) $h), $header);

        $byDate = [];

        while (($fields = fgetcsv($stream)) !== false) {
            if (!is_array($fields) || count($fields) < count($header)) {
                continue;
            }

            $row = array_combine($header, array_slice($fields, 0, count($header)));
            if (!is_array($row)) {
                continue;
            }

            if (($row['material'] ?? '') !== 'gold') {
                continue;
            }

            $date = (string) ($row['recordedDate'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $byDate[$date][] = $row;
        }

        fclose($stream);

        if ($byDate === []) {
            return [];
        }

        // Oldest first, then keep only the $dates most recent dates
        // (the batch one sync run imports).
        ksort($byDate);
        $dates = max(1, $dates);

        if (count($byDate) > $dates) {
            $byDate = array_slice($byDate, -$dates, $dates, true);
        }

        return $byDate;
    }

    /**
     * The row for one material type at one weight, or null when it
     * is missing or its sell price is not positive. A zero price
     * is the upstream's "no data" for that row (Galeri 24's ANTAM
     * 1-gram row is 0 at the latest date), so it never maps.
     *
     * @param array<int, array<string, string>> $rows
     * @return array<string, string>|null
     */
    private function findRow(array $rows, string $materialType, float $weight): ?array
    {
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['materialType'] ?? ''), $materialType) !== 0) {
                continue;
            }

            if ((float) ($row['weight'] ?? 0) !== $weight) {
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
     * The 1-gram Antam row in the Harga Emas.net file (materialType
     * "Emas LM Batangan Antam"), or null when it is missing or its
     * sell price is not positive.
     *
     * @param array<int, array<string, string>> $rows
     * @return array<string, string>|null
     */
    private function findAntamRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if ((float) ($row['weight'] ?? 0) !== 1.0) {
                continue;
            }

            if (stripos((string) ($row['materialType'] ?? ''), 'antam') === false) {
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
     * @param array<string, string> $row
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

    /** @param array<string, string> $row */
    private function recordedDate(array $row): ?string
    {
        $date = (string) ($row['recordedDate'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }
}

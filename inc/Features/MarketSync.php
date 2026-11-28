<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Providers\AbstractLiveProvider;

/**
 * Market Index sync: the gold-style sync for the strip's markets.
 *
 * Like the gold sync (LogamMuliaApiProvider::cronSync() + manual
 * sync), the market data is fetched on purpose and stored - a
 * manual sync action on the Market Index admin page, plus a daily
 * WP-Cron - instead of only refreshing when a page render happens
 * to hit a cold transient. The sync uses the market's configured
 * provider (Yahoo for stocks, forex and commodity by default,
 * CoinGecko for crypto); a market whose provider is Manual has
 * admin-typed snapshots and nothing to fetch, so it is skipped.
 *
 * One option holds the sync selection and the last run:
 * ['markets' => [stock, forex, ...], 'last_run' => int|null,
 * 'results' => [market => ['ok', 'updated', 'symbols', 'error',
 * 'skipped']], 'auto_sync' => bool, 'auto_sync_interval_hours'
 * => int]. A failed fetch is recorded in the status, not
 * hidden: the strip keeps serving the last good value with its
 * real timestamp, and the admin page says the fetch failed.
 *
 * The automatic sync follows the gold pattern
 * (LogamMuliaApiProvider::rescheduleSync()): auto-sync is ON by
 * default, once an hour, over the selected markets only; the
 * interval is a setting (see INTERVAL_HOURS), and registerSync()
 * reconciles the scheduled event with the saved settings on
 * every load, so a changed interval or a disabled auto-sync
 * takes effect without waiting for the next run.
 */
final class MarketSync
{
    public const OPTION = 'kk_markets_sync';

    public const CRON_HOOK = 'kk_markets_sync';

    /** Hours between automatic sync runs the admin may pick. */
    public const INTERVAL_HOURS = [1, 2, 3, 4, 6, 8, 12, 24];

    /** Default automatic sync interval: once an hour. */
    public const DEFAULT_INTERVAL_HOURS = 1;

    /** WP-Cron schedule name for one interval (hours). */
    public static function scheduleName(int $hours): string
    {
        return 'kk_markets_' . $hours . 'h';
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
                'display' => self::intervalLabels()[$hours] ?? '',
            ];
        }

        return $schedules;
    }

    /** @return array<int, string> Interval hours => translatable label. */
    public static function intervalLabels(): array
    {
        $labels = [];

        foreach (self::INTERVAL_HOURS as $hours) {
            $labels[$hours] = $hours === 1
                ? __('Every hour', 'koperasikita')
                : ($hours === 24
                    ? __('Once a day', 'koperasikita')
                    /* translators: %d: hours between sync runs. */
                    : sprintf(__('Every %d hours', 'koperasikita'), $hours));
        }

        return $labels;
    }

    /** An interval outside the list falls back to the hourly default. */
    public static function sanitizeAutoSyncInterval(mixed $value): int
    {
        $hours = is_numeric($value) ? (int) $value : self::DEFAULT_INTERVAL_HOURS;

        return in_array($hours, self::INTERVAL_HOURS, true) ? $hours : self::DEFAULT_INTERVAL_HOURS;
    }

    /**
     * The automatic sync settings, sanitized with the defaults
     * applied. Never saved = the defaults: auto-sync on, once an
     * hour - exactly today's behaviour, so the setting changes
     * nothing until the admin edits the sync form.
     *
     * @return array{enabled: bool, interval_hours: int}
     */
    public static function autoSyncSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => !isset($stored['auto_sync']) || (bool) $stored['auto_sync'],
            'interval_hours' => isset($stored['auto_sync_interval_hours'])
                ? self::sanitizeAutoSyncInterval($stored['auto_sync_interval_hours'])
                : self::DEFAULT_INTERVAL_HOURS,
        ];
    }

    /**
     * Save which markets the sync fetches (the sync form), without
     * fetching anything. The manual sync reads this selection.
     *
     * @param string[] $markets
     */
    public static function saveMarkets(array $markets): array
    {
        $status = self::status();
        $status['markets'] = self::sanitizeMarkets($markets);

        update_option(self::OPTION, $status);

        return $status;
    }

    /**
     * Save the automatic sync settings (the sync form) and apply
     * them to the schedule right away.
     */
    public static function saveAutoSync(bool $enabled, mixed $intervalHours): array
    {
        $status = self::status();
        $status['auto_sync'] = $enabled;
        $status['auto_sync_interval_hours'] = self::sanitizeAutoSyncInterval($intervalHours);

        update_option(self::OPTION, $status);
        self::rescheduleSync();

        return $status;
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
        $settings = self::autoSyncSettings();

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

    /** Timestamp of the next automatic run, or false when none is scheduled. */
    public static function nextRun(): int|false
    {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /** Whether wp-config.php disables WP-Cron (nothing triggers scheduled tasks on visits). */
    public static function isWpCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && (bool) DISABLE_WP_CRON;
    }

    /**
     * Whether the last sync run was successful and recent: within
     * twice the auto-sync interval, with at least one market
     * fetched fine. A manual sync counts too - it is the same
     * stored run the status shows.
     */
    private static function lastRunRecent(): bool
    {
        $status = self::status();

        if ($status['last_run'] === null) {
            return false;
        }

        $window = 2 * $status['auto_sync_interval_hours'] * HOUR_IN_SECONDS;

        if ($status['last_run'] < time() - $window) {
            return false;
        }

        foreach ($status['results'] as $row) {
            if ($row['ok']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evidence a server cronjob is calling wp-cron.php: the
     * automatic event is scheduled and the sync actually ran
     * recently. With WP-Cron disabled, visits never trigger the
     * event, so a recent run must have come from outside.
     */
    public static function hasCronjobEvidence(): bool
    {
        return self::nextRun() !== false && self::lastRunRecent();
    }

    /**
     * The WP-Cron state the Setup notice reports: 'normal' when
     * WP-Cron runs on visits, 'disabled-with-cronjob' when it is
     * disabled but a server cronjob is triggering the sync, and
     * 'disabled-no-cronjob' when it is disabled and nothing shows
     * the sync running.
     *
     * @return 'normal'|'disabled-with-cronjob'|'disabled-no-cronjob'
     */
    public static function wpCronState(): string
    {
        if (!self::isWpCronDisabled()) {
            return 'normal';
        }

        return self::hasCronjobEvidence() ? 'disabled-with-cronjob' : 'disabled-no-cronjob';
    }

    /** @return string[] Markets with a live provider the sync can fetch. Gold has its own sync. */
    public static function syncableMarkets(): array
    {
        return ['stock', 'forex', 'commodity', 'crypto'];
    }

    /** @return string[] Markets synced by default: the strip's live markets. */
    public static function defaultMarkets(): array
    {
        return ['stock', 'forex', 'commodity'];
    }

    /**
     * @param string[] $markets
     * @return string[] Known syncable markets, in syncable order, deduplicated.
     */
    public static function sanitizeMarkets(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];

        foreach (self::syncableMarkets() as $market) {
            foreach ($value as $row) {
                if (is_string($row) && $row === $market) {
                    $clean[] = $market;
                    break;
                }
            }
        }

        return $clean;
    }

    /** @return array{markets: string[], last_run: int|null, results: array<string, array{ok: bool, updated: int|null, symbols: int, error: string|null, skipped: bool}>, auto_sync: bool, auto_sync_interval_hours: int} */
    public static function status(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [
                'markets' => self::defaultMarkets(),
                'last_run' => null,
                'results' => [],
                'auto_sync' => true,
                'auto_sync_interval_hours' => self::DEFAULT_INTERVAL_HOURS,
            ];
        }

        $results = [];

        if (isset($stored['results']) && is_array($stored['results'])) {
            foreach ($stored['results'] as $market => $row) {
                if (!is_string($market) || !is_array($row)) {
                    continue;
                }

                $results[$market] = [
                    'ok' => !empty($row['ok']),
                    'updated' => isset($row['updated']) && is_numeric($row['updated']) ? (int) $row['updated'] : null,
                    'symbols' => isset($row['symbols']) ? (int) $row['symbols'] : 0,
                    'error' => isset($row['error']) && is_string($row['error']) && $row['error'] !== '' ? $row['error'] : null,
                    'skipped' => !empty($row['skipped']),
                ];
            }
        }

        $autoSync = self::autoSyncSettings();

        return [
            'markets' => isset($stored['markets']) ? self::sanitizeMarkets($stored['markets']) : self::defaultMarkets(),
            'last_run' => isset($stored['last_run']) && is_numeric($stored['last_run']) ? (int) $stored['last_run'] : null,
            'results' => $results,
            'auto_sync' => $autoSync['enabled'],
            'auto_sync_interval_hours' => $autoSync['interval_hours'],
        ];
    }

    /**
     * Sync the given markets: force a fresh fetch per market and
     * store it (transient + last good, via forceRefresh()), then
     * record the run. Markets not in the list keep their previous
     * stored data and their previous status row. Returns the
     * stored status after the run.
     *
     * @param string[] $markets
     * @return array{markets: string[], last_run: int|null, results: array<string, array{ok: bool, updated: int|null, symbols: int, error: string|null, skipped: bool}>, auto_sync: bool, auto_sync_interval_hours: int}
     */
    public static function syncMarkets(array $markets): array
    {
        $markets = self::sanitizeMarkets($markets);
        $status = self::status();
        $results = $status['results'];

        foreach ($markets as $market) {
            $results[$market] = self::fetchMarket($market);
        }

        $status = [
            'markets' => $markets,
            'last_run' => time(),
            'results' => $results,
            'auto_sync' => $status['auto_sync'],
            'auto_sync_interval_hours' => $status['auto_sync_interval_hours'],
        ];

        update_option(self::OPTION, $status);

        return $status;
    }

    /**
     * Sync one market and merge its result into the stored status,
     * without touching the saved market selection. The manual-sync
     * popup calls this once per selected market, in order, like
     * the gold sync calls once per source.
     *
     * @return array{market: string, ok: bool, updated: int|null, symbols: int, error: string|null, skipped: bool}
     */
    public static function syncOneMarket(string $market): array
    {
        $market = sanitize_key($market);
        $status = self::status();
        $row = self::fetchMarket($market);
        $status['results'][$market] = $row;
        $status['last_run'] = time();

        update_option(self::OPTION, $status);

        return array_merge(['market' => $market], $row);
    }

    /**
     * Fetch one market fresh and return its status row. Manual
     * snapshots are typed by the admin; there is nothing upstream
     * to fetch for this market.
     *
     * @return array{ok: bool, updated: int|null, symbols: int, error: string|null, skipped: bool}
     */
    private static function fetchMarket(string $market): array
    {
        $labels = Markets::labels();
        $provider = Markets::providerFor($market);

        if (!$provider instanceof AbstractLiveProvider) {
            // Manual snapshots are typed by the admin; there
            // is nothing upstream to fetch for this market.
            return [
                'ok' => true,
                'updated' => null,
                'symbols' => 0,
                'error' => null,
                'skipped' => true,
            ];
        }

        $fetched = $provider->forceRefresh($market);

        if ($fetched === null) {
            return [
                'ok' => false,
                'updated' => null,
                'symbols' => 0,
                /* translators: %s: market label, e.g. Stocks. */
                'error' => sprintf(__('Could not fetch the latest %s prices. The last good value is still shown.', 'koperasikita'), $labels[$market] ?? $market),
                'skipped' => false,
            ];
        }

        $updated = 0;

        foreach ($fetched as $entry) {
            if (is_array($entry) && isset($entry['updated'])) {
                $updated = max($updated, (int) $entry['updated']);
            }
        }

        return [
            'ok' => true,
            'updated' => $updated > 0 ? $updated : null,
            'symbols' => count($fetched),
            'error' => null,
            'skipped' => false,
        ];
    }

    /** WP-Cron callback: sync the markets the admin selected. */
    public static function cronSync(): void
    {
        self::syncMarkets(self::status()['markets']);
    }

    /**
     * Register the automatic sync cron. Called from
     * Markets::register() (which only runs when the Markets
     * feature is enabled). Reconciles the scheduled event with
     * the saved settings on every load: a changed interval or a
     * disabled auto-sync takes effect on the next page load, not
     * the next run.
     */
    public static function registerSync(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'cronSync']);
        add_filter('cron_schedules', [self::class, 'addCronSchedules']);

        self::rescheduleSync();
    }
}

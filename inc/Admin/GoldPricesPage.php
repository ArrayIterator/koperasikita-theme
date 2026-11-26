<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use KoperasiKita\Providers\GoldPricesTable;
use KoperasiKita\Providers\LogamMuliaApiProvider;

/**
 * Settings -> Gold Prices: the management page for the Logam Mulia
 * gold price sync, with three tabs.
 *
 * "Prices" shows the sync status (last sync, latest recorded date,
 * which symbols are stored, the failure backlog), a manual sync
 * button that steps through the sources one at a time via AJAX,
 * and which provider is active for gold. "Enabled Gold Brands" is
 * the master switch for which gold brands the site shows — a
 * disabled brand is hidden from the Gold widget and from the
 * widget's Brands to show picker. "History" shows the stored price
 * history as both a list (table) and a dependency-free inline SVG
 * line chart (one line per brand). Uses WP admin styles only — no
 * heavy custom CSS.
 */
final class GoldPricesPage
{
    public const PAGE_SLUG = 'kk-gold-prices';

    /** Rows shown in the history list. */
    private const HISTORY_ROWS = 60;

    /** Chart geometry (viewBox units, scales to the container). */
    private const CHART_WIDTH = 720;
    private const CHART_HEIGHT = 260;
    private const CHART_PAD_LEFT = 84;
    private const CHART_PAD_RIGHT = 16;
    private const CHART_PAD_TOP = 16;
    private const CHART_PAD_BOTTOM = 32;

    /**
     * Line colours per brand (theme green/gold first, then
     * distinct hues so the lines stay apart).
     *
     * @var array<string, string>
     */
    private const CHART_COLORS = [
        'antam' => '#0B6B3A',
        'ubs' => '#C9962E',
        'galeri-24' => '#2fa36b',
        'lotus-archi' => '#7c3aed',
        'pegadaian' => '#8A6D1B',
        'hartadinata' => '#2563eb',
        'king-halim' => '#dc2626',
        'emaskita' => '#6b7280',
    ];

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        self::handleBrandsSave();
        self::handleProviderSave();
        self::handleSyncSettingsSave();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is display-only.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'prices';
        $tab = in_array($tab, ['prices', 'brands', 'history'], true) ? $tab : 'prices';
        $brands = Gold::brands();
        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap" data-kk-gold-prices-page>
            <h1><?php esc_html_e('Gold Prices', 'koperasikita'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'prices', $pageUrl)); ?>" class="nav-tab<?php echo $tab === 'prices' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e('Prices', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'brands', $pageUrl)); ?>" class="nav-tab<?php echo $tab === 'brands' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e('Enabled Gold Brands', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'history', $pageUrl)); ?>" class="nav-tab<?php echo $tab === 'history' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e('History', 'koperasikita'); ?></a>
            </h2>
            <?php if ($tab === 'brands') : ?>
                <?php self::renderBrandsTab($brands); ?>
            <?php elseif ($tab === 'history') : ?>
                <?php self::renderHistoryTab($brands); ?>
            <?php else : ?>
                <?php self::renderPricesTab($brands); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save the enabled-brands form (Brands tab): nonce + capability
     * checked, then redirect back with a saved flag so a refresh
     * never resubmits the form.
     */
    private static function handleBrandsSave(): void
    {
        if (!isset($_POST['kk_enabled_brands_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_enabled_brands', 'kk_enabled_brands_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = isset($_POST['kk_enabled_brands']) ? wp_unslash($_POST['kk_enabled_brands']) : [];
        $slugs = is_array($raw)
            ? array_values(array_map('sanitize_key', $raw))
            : [];

        Gold::setEnabledBrands($slugs);

        wp_safe_redirect(add_query_arg(
            ['tab' => 'brands', 'saved' => '1'],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Save the gold provider form (Prices tab): nonce + capability
     * checked, then redirect back with a saved flag so a refresh
     * never resubmits the form.
     */
    private static function handleProviderSave(): void
    {
        if (!isset($_POST['kk_gold_provider_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_gold_provider', 'kk_gold_provider_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $provider = isset($_POST['kk_gold_provider']) ? sanitize_key((string) wp_unslash($_POST['kk_gold_provider'])) : 'manual';
        Markets::setProvider('gold', $provider);

        wp_safe_redirect(add_query_arg(
            ['tab' => 'prices', 'provider_saved' => '1'],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Save the automatic sync settings form (Prices tab): nonce +
     * capability checked, then redirect back with a saved flag so
     * a refresh never resubmits the form. Saving reschedules the
     * WP-Cron event to match (see LogamMuliaApiProvider).
     */
    private static function handleSyncSettingsSave(): void
    {
        if (!isset($_POST['kk_gold_sync_settings_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_gold_sync_settings', 'kk_gold_sync_settings_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        LogamMuliaApiProvider::saveSyncSettings([
            'enabled' => isset($raw['kk_sync_enabled']),
            'interval_hours' => isset($raw['kk_sync_interval']) ? (int) $raw['kk_sync_interval'] : 24,
            'batch_days' => isset($raw['kk_sync_batch']) ? (int) $raw['kk_sync_batch'] : 7,
            'timeout' => isset($raw['kk_sync_timeout']) ? (int) $raw['kk_sync_timeout'] : 8,
        ]);

        wp_safe_redirect(add_query_arg(
            ['tab' => 'prices', 'sync_settings_saved' => '1'],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Brands tab: the master switch for which gold brands the site
     * shows. A disabled brand is hidden from the Gold widget and
     * from the widget's "Brands to show" picker. Unchecking every
     * brand disables the gold slider on purpose.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderBrandsTab(array $brands): void
    {
        $enabled = Gold::enabledBrands();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved flag is display-only.
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        ?>
        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Enabled brands saved.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e('Choose which gold brands are shown on the site. Disabled brands are hidden from the Gold widget and from the widget\'s Brands to show picker. The widget\'s own Brands to show setting then picks from the enabled brands only.', 'koperasikita'); ?></p>
        <form method="post" action="<?php echo esc_url(add_query_arg('tab', 'brands', admin_url('admin.php?page=' . self::PAGE_SLUG))); ?>">
            <?php wp_nonce_field('kk_enabled_brands', 'kk_enabled_brands_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach ($brands as $slug => $label) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="kk_enabled_brands[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled, true)); ?>>
                                    <?php esc_html_e('Enabled', 'koperasikita'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save brands', 'koperasikita')); ?>
        </form>
        <?php
    }

    /**
     * Prices tab: the sync status, manual sync and active provider.
     * The price history lives on its own History tab.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderPricesTab(array $brands): void
    {
        $status = LogamMuliaApiProvider::status();
        $active = Markets::providerFor('gold');
        ?>
        <p><?php esc_html_e('Daily gold prices from the Logam Mulia API price history (backup/prices CSV on GitHub). The sync runs automatically via WP-Cron on the schedule below and only inserts rows that are not in the database yet — one row per brand per day. Use the button below to sync on demand.', 'koperasikita'); ?></p>

        <h2><?php esc_html_e('Sync status', 'koperasikita'); ?></h2>
        <?php self::renderStatus($status); ?>

        <h2><?php esc_html_e('Manual sync', 'koperasikita'); ?></h2>
        <p>
            <button type="button" class="button button-primary" data-kk-gold-sync><?php esc_html_e('Sync now', 'koperasikita'); ?></button>
        </p>
        <div data-kk-gold-sync-result role="status" aria-live="polite"></div>

        <h2><?php esc_html_e('Gold price provider', 'koperasikita'); ?></h2>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved flag is display-only.
        $providerSaved = isset($_GET['provider_saved']) && $_GET['provider_saved'] === '1';
        $currentProvider = Markets::settings()['providers']['gold'] ?? 'manual';
        if ($providerSaved) :
            ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Gold provider saved.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=prices')); ?>">
            <?php wp_nonce_field('kk_gold_provider', 'kk_gold_provider_nonce'); ?>
            <p>
                <label for="kk-gold-provider"><?php esc_html_e('Source for gold prices in the widget and market strip.', 'koperasikita'); ?></label><br>
                <select id="kk-gold-provider" name="kk_gold_provider">
                    <?php foreach (Markets::providerChoices('gold') as $id => $label) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($currentProvider, $id); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Save provider', 'koperasikita'), 'secondary', 'submit', false); ?>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: provider label. */
                    esc_html__('Gold prices on the site currently come from: %s.', 'koperasikita'),
                    '<strong>' . esc_html($active->label()) . '</strong>'
                );
                ?>
            </p>
        </form>

        <h2><?php esc_html_e('Automatic sync', 'koperasikita'); ?></h2>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved flag is display-only.
        $syncSettingsSaved = isset($_GET['sync_settings_saved']) && $_GET['sync_settings_saved'] === '1';
        $sync = LogamMuliaApiProvider::syncSettings();
        $nextRun = wp_next_scheduled(LogamMuliaApiProvider::CRON_HOOK);
        if ($syncSettingsSaved) :
            ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Automatic sync settings saved.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=prices')); ?>">
            <?php wp_nonce_field('kk_gold_sync_settings', 'kk_gold_sync_settings_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable automatic sync', 'koperasikita'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="kk_sync_enabled" value="1" <?php checked($sync['enabled']); ?>>
                                <?php esc_html_e('Sync automatically on schedule (WP-Cron).', 'koperasikita'); ?>
                            </label>
                            <p class="description">
                                <?php
                                if (!$sync['enabled']) {
                                    esc_html_e('Automatic sync is off. Only manual sync runs.', 'koperasikita');
                                } elseif ($nextRun !== false) {
                                    printf(
                                        /* translators: %s: next scheduled run date/time. */
                                        esc_html__('Next automatic sync: %s.', 'koperasikita'),
                                        esc_html(date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $nextRun))
                                    );
                                } else {
                                    esc_html_e('No automatic sync is scheduled yet — it will be scheduled on the next page load.', 'koperasikita');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-interval"><?php esc_html_e('How often', 'koperasikita'); ?></label></th>
                        <td>
                            <select id="kk-sync-interval" name="kk_sync_interval">
                                <?php foreach (LogamMuliaApiProvider::intervalChoices() as $hours => $label) : ?>
                                    <option value="<?php echo esc_attr((string) $hours); ?>" <?php selected($sync['interval_hours'], $hours); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-batch"><?php esc_html_e('Days per run', 'koperasikita'); ?></label></th>
                        <td>
                            <input type="number" id="kk-sync-batch" name="kk_sync_batch" value="<?php echo esc_attr((string) $sync['batch_days']); ?>" min="1" max="30" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('How many recent days of price history each run imports (1–30). A fresh site gets a week of chart history on its first sync.', 'koperasikita'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-timeout"><?php esc_html_e('Fetch timeout (seconds)', 'koperasikita'); ?></label></th>
                        <td>
                            <input type="number" id="kk-sync-timeout" name="kk_sync_timeout" value="<?php echo esc_attr((string) $sync['timeout']); ?>" min="5" max="60" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('How long to wait for each price file before giving up (5–60 seconds). A failed fetch is recorded in the backlog, never retried in a tight loop.', 'koperasikita'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Save sync settings', 'koperasikita'), 'secondary'); ?>
        </form>
        <?php
    }

    /**
     * History tab: the stored price history as a chart and a list.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderHistoryTab(array $brands): void
    {
        $history = GoldPricesTable::history(LogamMuliaApiProvider::PROVIDER_ID, 200);
        ?>
        <h2><?php esc_html_e('Price history', 'koperasikita'); ?></h2>
        <?php self::renderChart($history, $brands); ?>
        <?php self::renderHistoryTable($history, $brands); ?>
        <?php
    }

    /**
     * Status block: last sync, latest recorded date, symbols
     * present, inserted vs skipped, last error, and the per-source
     * table (with the failure backlog / lowest-priority note).
     *
     * @param array{synced_at: int|null, latest_date: string|null, symbols: array<int, string>, inserted: int, skipped: int, error: string|null, sources: array<string, array{synced_at: int|null, inserted: int, skipped: int, latest_date: string|null, error: string|null, failures: int, next_retry: int|null, deprioritised: bool}>, backlog: array<string, array{failures: int, last_error: string|null, last_attempt: int|null, next_retry: int|null, deprioritised: bool}>} $status
     */
    private static function renderStatus(array $status): void
    {
        $lastSync = $status['synced_at'] !== null
            ? date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $status['synced_at'])
            : __('Never', 'koperasikita');
        $symbols = $status['symbols'] !== [] ? implode(', ', $status['symbols']) : __('None yet', 'koperasikita');
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Last sync', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($lastSync); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Latest price date', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($status['latest_date'] ?? __('None yet', 'koperasikita')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Symbols stored', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($symbols); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last run', 'koperasikita'); ?></th>
                    <td>
                        <?php
                        printf(
                            /* translators: %1$d: rows inserted, %2$d: rows skipped (already in DB). */
                            esc_html__('Inserted %1$d, skipped %2$d (already in database).', 'koperasikita'),
                            (int) $status['inserted'],
                            (int) $status['skipped']
                        );
                        ?>
                    </td>
                </tr>
                <?php if ($status['error'] !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last error', 'koperasikita'); ?></th>
                        <td><?php echo esc_html($status['error']); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        self::renderSourceTable($status);
    }

    /**
     * Per-source table: latest date, inserted/skipped, and the
     * backlog note (a failed source stays in the backlog and is
     * retried later; after 3 failures it drops to lowest
     * priority — weekly retry only).
     *
     * @param array{sources: array<string, array{synced_at: int|null, inserted: int, skipped: int, latest_date: string|null, error: string|null, failures: int, next_retry: int|null, deprioritised: bool}>} $status
     */
    private static function renderSourceTable(array $status): void
    {
        $rows = '';
        foreach (LogamMuliaApiProvider::SOURCES as $source) {
            $s = $status['sources'][$source] ?? [];
            $failures = (int) ($s['failures'] ?? 0);
            $deprioritised = !empty($s['deprioritised']);
            $nextRetry = isset($s['next_retry']) ? (int) $s['next_retry'] : null;

            if ($deprioritised && $nextRetry !== null) {
                /* translators: %1$d: failure count, %2$s: next retry date. */
                $note = sprintf(
                    __('Lowest priority — failed %1$d×, next retry %2$s.', 'koperasikita'),
                    $failures,
                    date_i18n(get_option('date_format', 'Y-m-d'), $nextRetry)
                );
            } elseif ($failures > 0 && $nextRetry !== null) {
                /* translators: %1$d: failure count, %2$s: next retry date. */
                $note = sprintf(
                    __('In backlog — failed %1$d×, next retry %2$s.', 'koperasikita'),
                    $failures,
                    date_i18n(get_option('date_format', 'Y-m-d'), $nextRetry)
                );
            } elseif (!empty($s['error'])) {
                $note = (string) $s['error'];
            } else {
                $note = '—';
            }

            $rows .= '<tr>'
                . '<td><strong>' . esc_html($source) . '</strong></td>'
                . '<td>' . esc_html($s['latest_date'] ?? '—') . '</td>'
                . '<td>' . esc_html((string) ($s['inserted'] ?? 0)) . '</td>'
                . '<td>' . esc_html((string) ($s['skipped'] ?? 0)) . '</td>'
                . '<td>' . esc_html($note) . '</td>'
                . '</tr>';
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Source', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Latest date', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Inserted', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Skipped', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Status / backlog', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped above. ?></tbody>
        </table>
        <?php
    }

    /**
     * Dependency-free inline SVG line chart: one line per brand,
     * sell price (per gram, IDR) over recorded date. Empty history
     * renders a short note instead of an empty chart.
     *
     * @param array<int, array{symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}> $history
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderChart(array $history, array $brands): void
    {
        // Group by symbol, oldest first, keeping only rows with a
        // date and a positive price.
        $series = [];
        foreach ($history as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            $date = (string) ($row['recorded_date'] ?? '');
            $price = (float) ($row['sell_price'] ?? 0);

            if ($symbol === '' || $date === '' || $price <= 0) {
                continue;
            }

            $series[$symbol][] = ['date' => $date, 'price' => $price];
        }

        foreach ($series as $symbol => $points) {
            usort($points, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
            $series[$symbol] = $points;
        }

        if ($series === []) {
            echo '<p>' . esc_html__('No prices stored yet. Run a sync to fill the chart.', 'koperasikita') . '</p>';

            return;
        }

        $allDates = [];
        $min = null;
        $max = null;
        foreach ($series as $points) {
            foreach ($points as $point) {
                $allDates[] = $point['date'];
                $min = $min === null ? $point['price'] : min($min, $point['price']);
                $max = $max === null ? $point['price'] : max($max, $point['price']);
            }
        }

        $allDates = array_values(array_unique($allDates));
        sort($allDates);
        $firstDate = $allDates[0];
        $lastDate = $allDates[count($allDates) - 1];

        // Pad the price range so the lines never sit on the edge.
        $span = (float) ($max - $min);
        $pad = $span > 0 ? $span * 0.1 : (float) $max * 0.05;
        $min -= $pad;
        $max += $pad;

        $plotW = self::CHART_WIDTH - self::CHART_PAD_LEFT - self::CHART_PAD_RIGHT;
        $plotH = self::CHART_HEIGHT - self::CHART_PAD_TOP - self::CHART_PAD_BOTTOM;
        $start = strtotime($firstDate . ' 00:00:00');
        $end = strtotime($lastDate . ' 00:00:00');
        $timeSpan = max(1, $end - $start);

        $x = static fn(string $date): float => self::CHART_PAD_LEFT + ((strtotime($date . ' 00:00:00') - $start) / $timeSpan) * $plotW;
        $y = static fn(float $price): float => self::CHART_PAD_TOP + (1 - ($price - $min) / max(1.0, $max - $min)) * $plotH;

        $lines = '';
        $legend = '';
        foreach ($series as $symbol => $points) {
            $color = self::CHART_COLORS[$symbol] ?? '#374151';
            $coords = [];
            foreach ($points as $point) {
                $coords[] = number_format($x($point['date']), 1, '.', '') . ',' . number_format($y($point['price']), 1, '.', '');
            }

            $lines .= '<polyline fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" points="' . esc_attr(implode(' ', $coords)) . '" />';

            $label = $brands[$symbol] ?? $symbol;
            $legend .= '<span style="display:inline-block;margin-right:1rem">'
                . '<span style="display:inline-block;width:0.75rem;height:0.75rem;background:' . esc_attr($color) . ';border-radius:2px;margin-right:0.25rem"></span>'
                . esc_html($label)
                . '</span>';
        }
        ?>
        <figure style="margin:0 0 1rem">
            <svg viewBox="0 0 <?php echo (int) self::CHART_WIDTH; ?> <?php echo (int) self::CHART_HEIGHT; ?>" width="100%" role="img" aria-label="<?php esc_attr_e('Gold price history per brand', 'koperasikita'); ?>">
                <line x1="<?php echo (int) self::CHART_PAD_LEFT; ?>" y1="<?php echo (int) self::CHART_PAD_TOP; ?>" x2="<?php echo (int) self::CHART_PAD_LEFT; ?>" y2="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" stroke="#d1d5db" />
                <line x1="<?php echo (int) self::CHART_PAD_LEFT; ?>" y1="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" x2="<?php echo (int) (self::CHART_WIDTH - self::CHART_PAD_RIGHT); ?>" y2="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" stroke="#d1d5db" />
                <text x="<?php echo (int) (self::CHART_PAD_LEFT - 8); ?>" y="<?php echo (int) (self::CHART_PAD_TOP + 4); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html(number_format_i18n((int) round($max))); ?></text>
                <text x="<?php echo (int) (self::CHART_PAD_LEFT - 8); ?>" y="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html(number_format_i18n((int) round($min))); ?></text>
                <text x="<?php echo (int) self::CHART_PAD_LEFT; ?>" y="<?php echo (int) (self::CHART_HEIGHT - 8); ?>" font-size="11" fill="#6b7280"><?php echo esc_html($firstDate); ?></text>
                <text x="<?php echo (int) (self::CHART_WIDTH - self::CHART_PAD_RIGHT); ?>" y="<?php echo (int) (self::CHART_HEIGHT - 8); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html($lastDate); ?></text>
                <?php echo $lines; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- coordinates and colours are escaped above. ?>
            </svg>
            <figcaption><?php echo $legend; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- labels and colours are escaped above. ?></figcaption>
        </figure>
        <?php
    }

    /**
     * History list: latest per brand first (the table order is
     * recorded_date DESC), with date, brand, sell and buyback
     * price per gram in IDR.
     *
     * @param array<int, array{symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}> $history
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderHistoryTable(array $history, array $brands): void
    {
        if ($history === []) {
            echo '<p>' . esc_html__('No prices stored yet. Run a sync to fill the table.', 'koperasikita') . '</p>';

            return;
        }

        $rows = '';
        foreach (array_slice($history, 0, self::HISTORY_ROWS) as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            $label = $brands[$symbol] ?? $symbol;
            $sell = number_format_i18n((float) ($row['sell_price'] ?? 0), 0);
            $buyback = isset($row['buyback_price']) && $row['buyback_price'] !== null
                ? number_format_i18n((float) $row['buyback_price'], 0)
                : '—';

            $rows .= '<tr>'
                . '<td>' . esc_html((string) ($row['recorded_date'] ?? '—')) . '</td>'
                . '<td><strong>' . esc_html($label) . '</strong></td>'
                . '<td>' . esc_html($sell) . '</td>'
                . '<td>' . esc_html($buyback) . '</td>'
                . '</tr>';
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Brand', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Sell (per gram)', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Buyback (per gram)', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped above. ?></tbody>
        </table>
        <?php
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Features\Markets;
use KoperasiKita\Features\MarketSync;

/**
 * Koperasi Kita -> Market Index: the market data page.
 *
 * This page is for fetching data, not for display: it manages
 * the market sync (which markets are fetched, when they were
 * last fetched, and whether the fetch worked). What the strip
 * shows, and how, is chosen in the Customizer's Market Index
 * section, which shares the same option.
 *
 * Server-rendered: the sync form submits, and saves run on the
 * load hook (never in render(), which only reads the stored
 * status).
 */
final class MarketHeaderPage
{
    public const PAGE_SLUG = 'kk-market-header';

    /** Register the manual-sync AJAX endpoint. Called from Markets::register(). */
    public static function register(): void
    {
        Ajax::add('kk_market_sync', [self::class, 'ajaxSync'], false, 'manage_options');
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $syncStatus = MarketSync::status();
        ?>
        <div class="wrap kk-market-header-page">
            <h1 class="kk-market-header-title"><?php esc_html_e('Market Index', 'koperasikita'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'koperasikita'); ?></p></div>
            <?php endif; ?>

            <section class="kk-market-header-card">
                <div class="kk-market-header-card-head">
                    <h2 class="kk-market-header-card-title"><?php esc_html_e('Sync', 'koperasikita'); ?></h2>
                    <button type="button" class="button" data-kk-market-sync <?php disabled($syncStatus['markets'] === []); ?>><?php esc_html_e('Sync now', 'koperasikita'); ?></button>
                </div>
                <p><?php esc_html_e('The strip data is fetched by a sync and stored, like the gold prices: a manual sync here, plus an automatic sync on a schedule. A market that is not selected is not synced and keeps its stored data.', 'koperasikita'); ?></p>

                <table class="form-table kk-market-header-facts" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last sync', 'koperasikita'); ?></th>
                            <td><?php echo $syncStatus['last_run'] !== null ? esc_html(wp_date('j F Y, H:i', $syncStatus['last_run'])) : esc_html__('Never synced yet.', 'koperasikita'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Auto-sync', 'koperasikita'); ?></th>
                            <td><?php echo $syncStatus['auto_sync'] ? esc_html(MarketSync::intervalLabels()[$syncStatus['auto_sync_interval_hours']] ?? '') : esc_html__('Off', 'koperasikita'); ?></td>
                        </tr>
                        <?php if ($syncStatus['auto_sync']) : ?>
                            <?php $nextRun = MarketSync::nextRun(); ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Next sync', 'koperasikita'); ?></th>
                                <td><?php echo $nextRun !== false ? esc_html(wp_date('j F Y, H:i', $nextRun)) : esc_html__('Not scheduled.', 'koperasikita'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p data-kk-market-sync-running hidden><em><?php esc_html_e('A manual sync is running. Automatic sync settings are paused until it finishes.', 'koperasikita'); ?></em></p>
                <form method="post" data-kk-market-sync-settings action="<?php echo esc_url($pageUrl); ?>">
                    <?php wp_nonce_field('kk_market_header_sync', 'kk_market_header_sync_nonce'); ?>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="kk-market-header-check"><span class="screen-reader-text"><?php esc_html_e('Sync', 'koperasikita'); ?></span></th>
                                <th scope="col"><?php esc_html_e('Market', 'koperasikita'); ?></th>
                                <th scope="col"><?php esc_html_e('Status', 'koperasikita'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (MarketSync::syncableMarkets() as $market) : ?>
                                <?php $row = $syncStatus['results'][$market] ?? null; ?>
                                <tr>
                                    <td class="kk-market-header-check"><input type="checkbox" id="kk-market-sync-<?php echo esc_attr($market); ?>" name="kk_market_sync_markets[]" value="<?php echo esc_attr($market); ?>" <?php checked(in_array($market, $syncStatus['markets'], true)); ?>></td>
                                    <td><label for="kk-market-sync-<?php echo esc_attr($market); ?>"><?php echo esc_html(Markets::labels()[$market] ?? $market); ?></label></td>
                                    <td>
                                        <?php if ($row === null) : ?>
                                            <?php esc_html_e('Never synced yet.', 'koperasikita'); ?>
                                        <?php elseif ($row['skipped']) : ?>
                                            <?php esc_html_e('Skipped — manual snapshots are not synced.', 'koperasikita'); ?>
                                        <?php elseif (!$row['ok']) : ?>
                                            <?php echo esc_html($row['error'] ?? __('Sync failed.', 'koperasikita')); ?>
                                        <?php elseif ($row['updated'] !== null) : ?>
                                            <?php echo esc_html(sprintf(
                                                /* translators: 1: the time the data was fetched, 2: how many symbols were fetched. */
                                                __('Synced %1$s (%2$d symbols)', 'koperasikita'),
                                                wp_date('H:i', $row['updated']),
                                                $row['symbols']
                                            )); ?>
                                        <?php else : ?>
                                            <?php esc_html_e('Synced.', 'koperasikita'); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td class="kk-market-header-check"><input type="checkbox" id="kk-market-auto-sync" name="kk_market_auto_sync" value="1" <?php checked($syncStatus['auto_sync']); ?>></td>
                                <td><label for="kk-market-auto-sync"><?php esc_html_e('Enable auto-sync', 'koperasikita'); ?></label></td>
                                <td><span class="description"><?php esc_html_e('Fetches the selected markets automatically on a schedule, like the manual sync.', 'koperasikita'); ?></span></td>
                            </tr>
                            <tr>
                                <td class="kk-market-header-check"></td>
                                <td><label for="kk-market-auto-sync-interval"><?php esc_html_e('Auto-sync interval', 'koperasikita'); ?></label></td>
                                <td>
                                    <select id="kk-market-auto-sync-interval" name="kk_market_auto_sync_interval_hours">
                                        <?php foreach (MarketSync::intervalLabels() as $hours => $label) : ?>
                                            <option value="<?php echo esc_attr((string) $hours); ?>" <?php selected($syncStatus['auto_sync_interval_hours'], $hours); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="description"><?php esc_html_e('Markets to sync. A market that is not selected is not synced.', 'koperasikita'); ?></p>

                    <p class="kk-market-header-card-actions">
                        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save settings', 'koperasikita'); ?>">
                    </p>
                </form>

                <?php if ($syncStatus['markets'] === []) : ?>
                    <p><?php esc_html_e('No market is selected for sync. Select one above and save the settings first.', 'koperasikita'); ?></p>
                <?php endif; ?>
                <div class="kk-data-desk-popup" id="kk-market-sync-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-market-sync-popup-title">
                    <div class="kk-data-desk-popup-backdrop" data-kk-market-sync-popup-close></div>
                    <div class="kk-data-desk-popup-panel" role="document">
                        <div class="kk-data-desk-popup-head">
                            <h2 id="kk-market-sync-popup-title"><?php esc_html_e('Manual sync', 'koperasikita'); ?></h2>
                            <div class="kk-data-desk-popup-actions">
                                <button type="button" class="button" data-kk-market-sync-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                            </div>
                        </div>
                        <div class="kk-data-desk-popup-body">
                            <p><progress data-kk-market-sync-bar max="100" value="0" style="width: 100%; max-width: 30rem;"></progress></p>
                            <p data-kk-market-sync-status role="status" aria-live="polite"></p>
                            <ul data-kk-market-sync-steps aria-live="polite"></ul>
                            <div data-kk-market-sync-result role="status" aria-live="polite"></div>
                            <p class="description"><?php esc_html_e('Closing this popup reloads the page so the last sync and status show the new data.', 'koperasikita'); ?></p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * Handles the settings form on the load hook: it persists the
     * market selection and auto-sync settings, then redirects so
     * a refresh never resubmits. The manual sync itself runs over
     * AJAX (see ajaxSync()) and never posts this form.
     */
    public static function handleActionsOnLoad(): void
    {
        if (!isset($_POST['kk_market_header_sync_nonce'])) {
            return;
        }

        self::handleSettingsOnLoad();
    }

    /**
     * The settings form: save which markets to sync and the
     * auto-sync schedule, then redirect so a refresh never
     * resubmits.
     */
    private static function handleSettingsOnLoad(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_market_header_sync', 'kk_market_header_sync_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by MarketSync.
        $raw = wp_unslash($_POST);

        $markets = isset($raw['kk_market_sync_markets']) && is_array($raw['kk_market_sync_markets']) ? $raw['kk_market_sync_markets'] : [];

        MarketSync::saveMarkets($markets);

        MarketSync::saveAutoSync(
            isset($raw['kk_market_auto_sync']) && $raw['kk_market_auto_sync'] === '1',
            $raw['kk_market_auto_sync_interval_hours'] ?? MarketSync::DEFAULT_INTERVAL_HOURS
        );

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * Manual sync, one market per call: the popup (admin.ts
     * initMarketSync) calls this once per selected market, in
     * order, like the gold sync calls once per source. The first
     * call sends no market and starts from the first selected
     * one; each answer names the next market, or null when done.
     *
     * @return array{market: string, label: string, ok: bool, updated: int|null, symbols: int, error: string|null, skipped: bool, next: string|null, total: int, step: int}
     */
    public static function ajaxSync(): array
    {
        $status = MarketSync::status();
        $markets = $status['markets'];
        $labels = Markets::labels();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = isset($_POST['market']) ? wp_unslash($_POST['market']) : '';
        $market = is_string($raw) ? sanitize_key($raw) : '';

        if ($markets === []) {
            return [
                'market' => '',
                'label' => '',
                'ok' => false,
                'updated' => null,
                'symbols' => 0,
                'error' => __('No market is selected for sync. Select one above and save the settings first.', 'koperasikita'),
                'skipped' => false,
                'next' => null,
                'total' => 0,
                'step' => 0,
            ];
        }

        if ($market === '') {
            $market = $markets[0];
        } elseif (!in_array($market, $markets, true)) {
            throw new ApiException('kk_invalid_market', __('Unknown market.', 'koperasikita'), 400);
        }

        $row = MarketSync::syncOneMarket($market);
        $index = array_search($market, $markets, true);
        $next = ($index !== false && isset($markets[$index + 1])) ? $markets[$index + 1] : null;

        return array_merge($row, [
            'label' => $labels[$market] ?? $market,
            'next' => $next,
            'total' => count($markets),
            'step' => ($index === false ? 0 : $index) + 1,
        ]);
    }
}

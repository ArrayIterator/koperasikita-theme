<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Features\MarketHeaderIndex;
use KoperasiKita\Features\Markets;
use KoperasiKita\Features\MarketSync;
use KoperasiKita\Widgets\MarketsWidget;

/**
 * Koperasi Kita -> Market Index: the market data page.
 *
 * This page is for fetching data, not for display toggles: it
 * manages the market index strip's items (add/remove/reorder,
 * show/hide each) and the market sync (which markets are fetched,
 * when they were last fetched, and whether the fetch worked).
 * The item list is also the pool the Customizer picks from: an
 * item hidden here (the "Show" checkbox) cannot appear in the
 * Customizer's Market Index section. Whether the strip shows at
 * all is chosen in the Customizer, not here.
 *
 * Server-rendered: every button submits the form, and saves run
 * on the load hook (never in render(), which only reads the saved
 * options). The one exception is the move up/down buttons: those
 * reorder the list in the browser with JS and persist the new
 * order over AJAX, without a page reload.
 *
 * The item list is the picked set in picked order; the row order in
 * the form is the strip order. Removing every item falls back to
 * the default set (MarketHeaderIndex::settings), the same empty
 * rule as the Customizer picker.
 */
final class MarketHeaderPage
{
    public const PAGE_SLUG = 'kk-market-header';

    /** Register the move-order AJAX endpoint. Called from Markets::register(). */
    public static function register(): void
    {
        Ajax::add('kk_market_header_move', [self::class, 'ajaxMove'], false, 'manage_options');
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $settings = MarketHeaderIndex::settings();
        $all = MarketsWidget::allItems();
        $picked = [];

        foreach ($settings['items'] as $row) {
            $picked[$row['item']] = true;
        }

        $remaining = array_diff_key($all, $picked);
        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
        $synced = isset($_GET['synced']) && $_GET['synced'] === '1';
        $syncStatus = MarketSync::status();
        $syncFailed = false;

        if ($synced) {
            foreach ($syncStatus['results'] as $row) {
                if (!$row['ok']) {
                    $syncFailed = true;
                    break;
                }
            }
        }
        ?>
        <div class="wrap kk-market-header-page">
            <h1 class="kk-market-header-title"><?php esc_html_e('Market Index', 'koperasikita'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'koperasikita'); ?></p></div>
            <?php endif; ?>

            <?php if ($synced && !$syncFailed) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Sync complete.', 'koperasikita'); ?></p></div>
            <?php endif; ?>

            <?php if ($synced && $syncFailed) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Sync finished with errors — see the status below.', 'koperasikita'); ?></p></div>
            <?php endif; ?>

            <section class="kk-market-header-card">
                <h2 class="kk-market-header-card-title"><?php esc_html_e('Strip items', 'koperasikita'); ?></h2>
                <p><?php esc_html_e('The market index strip below the header shows the picked market indices - symbol, price and day change - with a snapshot caption. Data comes only from the Markets providers; an item with no price is skipped, never invented.', 'koperasikita'); ?></p>

                <form method="post" action="<?php echo esc_url($pageUrl); ?>">
                    <?php wp_nonce_field('kk_market_header', 'kk_market_header_nonce'); ?>

                    <p>
                        <label for="kk-market-header-cycle-interval"><?php esc_html_e('Seconds between items on mobile', 'koperasikita'); ?></label>
                        <input type="number" id="kk-market-header-cycle-interval" name="kk_market_header_cycle_interval" min="2" max="120" step="1" value="<?php echo esc_attr((string) $settings['cycle_interval']); ?>">
                        <span class="description"><?php esc_html_e('On small screens the strip shows one item at a time; this is how long each item shows.', 'koperasikita'); ?></span>
                    </p>

                    <p>
                        <label for="kk-market-header-snapshot-label"><?php esc_html_e('Snapshot label', 'koperasikita'); ?></label>
                        <input type="text" id="kk-market-header-snapshot-label" name="kk_market_header_snapshot_label" class="regular-text" value="<?php echo esc_attr($settings['snapshot_label']); ?>">
                        <span class="description"><?php echo esc_html(sprintf(
                            /* translators: %s: the default snapshot label. */
                            __('The text before the date and time. Leave empty to hide the label. Default: %s.', 'koperasikita'),
                            __('Market Snapshot', 'koperasikita')
                        )); ?></span>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="kk_market_header_snapshot_show_datetime" value="1" <?php checked($settings['snapshot_show_datetime']); ?>>
                            <?php esc_html_e('Show day and time', 'koperasikita'); ?>
                        </label>
                        <span class="description"><?php esc_html_e('Shows the day and time the strip data was taken, after the label.', 'koperasikita'); ?></span>
                    </p>

                    <p>
                        <label for="kk-market-header-snapshot-format"><?php esc_html_e('Day and time format', 'koperasikita'); ?></label>
                        <input type="text" id="kk-market-header-snapshot-format" name="kk_market_header_snapshot_format" class="regular-text" value="<?php echo esc_attr($settings['snapshot_format']); ?>">
                        <span class="description"><?php echo esc_html(sprintf(
                            /* translators: 1: the default date format, 2: an example rendered with it. */
                            __('PHP date format for the snapshot day and time. Default: %1$s — for example: %2$s.', 'koperasikita'),
                            MarketHeaderIndex::DEFAULT_SNAPSHOT_FORMAT,
                            wp_date(MarketHeaderIndex::DEFAULT_SNAPSHOT_FORMAT, time())
                        )); ?></span>
                    </p>

                    <div class="kk-market-header-table-wrap">
                        <table class="wp-list-table widefat fixed striped" data-kk-market-header-table>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Item', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Show', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Order', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Actions', 'koperasikita'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $lastIndex = count($settings['items']) - 1; ?>
                                <?php foreach ($settings['items'] as $index => $row) : ?>
                                    <tr data-item="<?php echo esc_attr($row['item']); ?>">
                                        <td>
                                            <?php echo esc_html($all[$row['item']] ?? $row['item']); ?>
                                            <input type="hidden" name="kk_market_header_items[]" value="<?php echo esc_attr($row['item']); ?>">
                                        </td>
                                        <td>
                                            <input type="checkbox" name="kk_market_header_shown[]" value="<?php echo esc_attr($row['item']); ?>" <?php checked(!$row['hidden']); ?> aria-label="<?php esc_attr_e('Show', 'koperasikita'); ?>">
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small kk-market-header-move" data-direction="up" data-item="<?php echo esc_attr($row['item']); ?>" aria-label="<?php esc_attr_e('Move up', 'koperasikita'); ?>" <?php disabled($index === 0); ?>>&uarr;</button>
                                            <button type="button" class="button button-small kk-market-header-move" data-direction="down" data-item="<?php echo esc_attr($row['item']); ?>" aria-label="<?php esc_attr_e('Move down', 'koperasikita'); ?>" <?php disabled($index === $lastIndex); ?>>&darr;</button>
                                        </td>
                                        <td>
                                            <button type="submit" name="kk_market_header_action" value="<?php echo esc_attr('remove:' . $row['item']); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Remove this item from the strip?', 'koperasikita')); ?>');"><?php esc_html_e('Remove', 'koperasikita'); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="kk-market-header-move-status" data-kk-market-header-move-status role="status" aria-live="polite" hidden></p>
                    </div>

                    <?php if (count($settings['items']) < MarketsWidget::MAX_ITEMS) : ?>
                        <p class="kk-market-header-add">
                            <select name="kk_market_header_add" aria-label="<?php esc_attr_e('Add item', 'koperasikita'); ?>">
                                <option value=""><?php esc_html_e('Add item…', 'koperasikita'); ?></option>
                                <?php foreach ($remaining as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="kk_market_header_action" value="add" class="button"><?php esc_html_e('Add', 'koperasikita'); ?></button>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e('Maximum 16 items.', 'koperasikita'); ?></p>
                    <?php endif; ?>

                    <p class="kk-market-header-card-actions">
                        <input type="submit" class="button button-primary kk-market-header-button kk-market-header-button--primary" value="<?php esc_attr_e('Save', 'koperasikita'); ?>">
                    </p>
                </form>
            </section>

            <section class="kk-market-header-card">
                <h2 class="kk-market-header-card-title"><?php esc_html_e('Sync', 'koperasikita'); ?></h2>
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

                <?php if ($syncStatus['results'] !== []) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Market', 'koperasikita'); ?></th>
                                <th scope="col"><?php esc_html_e('Status', 'koperasikita'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (MarketSync::syncableMarkets() as $market) : ?>
                                <?php if (!isset($syncStatus['results'][$market])) {
                                    continue;
                                } ?>
                                <?php $row = $syncStatus['results'][$market]; ?>
                                <tr>
                                    <td><?php echo esc_html(Markets::labels()[$market] ?? $market); ?></td>
                                    <td>
                                        <?php if ($row['skipped']) : ?>
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
                        </tbody>
                    </table>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url($pageUrl); ?>">
                    <?php wp_nonce_field('kk_market_header_sync', 'kk_market_header_sync_nonce'); ?>

                    <p>
                        <?php foreach (MarketSync::syncableMarkets() as $market) : ?>
                            <label>
                                <input type="checkbox" name="kk_market_sync_markets[]" value="<?php echo esc_attr($market); ?>" <?php checked(in_array($market, $syncStatus['markets'], true)); ?>>
                                <?php echo esc_html(Markets::labels()[$market] ?? $market); ?>
                            </label>
                        <?php endforeach; ?>
                        <span class="description"><?php esc_html_e('Markets to sync. A market that is not selected is not synced.', 'koperasikita'); ?></span>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="kk_market_auto_sync" value="1" <?php checked($syncStatus['auto_sync']); ?>>
                            <?php esc_html_e('Enable auto-sync', 'koperasikita'); ?>
                        </label>
                        <span class="description"><?php esc_html_e('Fetches the selected markets automatically on a schedule, like the manual sync.', 'koperasikita'); ?></span>
                    </p>

                    <p>
                        <label for="kk-market-auto-sync-interval"><?php esc_html_e('Auto-sync interval', 'koperasikita'); ?></label>
                        <select id="kk-market-auto-sync-interval" name="kk_market_auto_sync_interval_hours">
                            <?php foreach (MarketSync::intervalLabels() as $hours => $label) : ?>
                                <option value="<?php echo esc_attr((string) $hours); ?>" <?php selected($syncStatus['auto_sync_interval_hours'], $hours); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p class="kk-market-header-card-actions">
                        <input type="submit" class="button button-primary kk-market-header-button kk-market-header-button--primary" value="<?php esc_attr_e('Sync now', 'koperasikita'); ?>">
                    </p>
                </form>
            </section>
        </div>
        <?php
    }

    /**
     * Handles the forms on the load hook: every submit (save, add,
     * remove, sync) persists the current visibility state plus its
     * own action, then redirects so a refresh never resubmits.
     * The add/remove buttons carry "verb:item"; add takes the item
     * from the select. The move up/down buttons do not submit at
     * all - they reorder over AJAX (see ajaxMove()).
     */
    public static function handleActionsOnLoad(): void
    {
        if (isset($_POST['kk_market_header_sync_nonce'])) {
            self::handleSyncOnLoad();

            return;
        }

        if (!isset($_POST['kk_market_header_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_market_header', 'kk_market_header_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        $cycleInterval = $raw['kk_market_header_cycle_interval'] ?? MarketHeaderIndex::DEFAULT_CYCLE_INTERVAL;

        $ordered = [];

        if (isset($raw['kk_market_header_items']) && is_array($raw['kk_market_header_items'])) {
            foreach ($raw['kk_market_header_items'] as $item) {
                if (is_string($item) && $item !== '') {
                    $ordered[] = $item;
                }
            }
        }

        $shown = [];

        if (isset($raw['kk_market_header_shown']) && is_array($raw['kk_market_header_shown'])) {
            foreach ($raw['kk_market_header_shown'] as $item) {
                if (is_string($item)) {
                    $shown[$item] = true;
                }
            }
        }

        $items = [];

        foreach ($ordered as $item) {
            $items[] = ['item' => $item, 'hidden' => !isset($shown[$item])];
        }

        $action = isset($raw['kk_market_header_action']) && is_string($raw['kk_market_header_action']) ? $raw['kk_market_header_action'] : '';
        [$verb, $target] = array_pad(explode(':', $action, 2), 2, '');

        if ($verb === 'add') {
            $add = isset($raw['kk_market_header_add']) && is_string($raw['kk_market_header_add']) ? $raw['kk_market_header_add'] : '';

            if ($add !== '' && !in_array($add, array_column($items, 'item'), true)) {
                $items[] = ['item' => $add, 'hidden' => false];
            }
        } elseif ($verb === 'remove' && $target !== '') {
            $items = array_values(array_filter($items, static fn (array $row): bool => $row['item'] !== $target));
        }

        MarketHeaderIndex::update([
            'items' => $items,
            'cycle_interval' => $cycleInterval,
            'snapshot_label' => isset($raw['kk_market_header_snapshot_label']) && is_string($raw['kk_market_header_snapshot_label']) ? $raw['kk_market_header_snapshot_label'] : null,
            'snapshot_show_datetime' => isset($raw['kk_market_header_snapshot_show_datetime']) && $raw['kk_market_header_snapshot_show_datetime'] === '1',
            'snapshot_format' => isset($raw['kk_market_header_snapshot_format']) && is_string($raw['kk_market_header_snapshot_format']) ? $raw['kk_market_header_snapshot_format'] : null,
        ]);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * The sync form: save which markets to sync, fetch them, then
     * redirect so a refresh never re-syncs. The status (last run,
     * per-market result) is stored by MarketSync and shown on the
     * next load.
     */
    private static function handleSyncOnLoad(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_market_header_sync', 'kk_market_header_sync_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by MarketSync.
        $raw = wp_unslash($_POST);

        $markets = isset($raw['kk_market_sync_markets']) && is_array($raw['kk_market_sync_markets']) ? $raw['kk_market_sync_markets'] : [];

        MarketSync::saveAutoSync(
            isset($raw['kk_market_auto_sync']) && $raw['kk_market_auto_sync'] === '1',
            $raw['kk_market_auto_sync_interval_hours'] ?? MarketSync::DEFAULT_INTERVAL_HOURS
        );

        MarketSync::syncMarkets($markets);

        wp_safe_redirect(add_query_arg('synced', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * Move one item up or down, over AJAX: the JS sends the full
     * new order (the hidden inputs, in DOM order after the swap);
     * the server re-applies the stored show/hide flags, saves the
     * order, and answers with it. The admin page's Save button
     * still works afterwards - it submits the same order.
     *
     * @return array{items: string[]}
     */
    public static function ajaxMove(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitised below.
        $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';

        if (!is_string($raw) || $raw === '') {
            throw new ApiException('kk_invalid_order', __('No order was sent.', 'koperasikita'), 400);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException('kk_invalid_order', __('The order could not be read.', 'koperasikita'), 400);
        }

        $stored = MarketHeaderIndex::settings();
        $hidden = [];

        foreach ($stored['items'] as $row) {
            $hidden[$row['item']] = $row['hidden'];
        }

        $items = [];

        foreach (MarketHeaderIndex::sanitizeItems($decoded) as $row) {
            $items[] = ['item' => $row['item'], 'hidden' => $hidden[$row['item']] ?? false];
        }

        if ($items === []) {
            throw new ApiException('kk_invalid_order', __('The order is empty.', 'koperasikita'), 400);
        }

        MarketHeaderIndex::update(['items' => $items]);

        return ['items' => array_column($items, 'item')];
    }
}

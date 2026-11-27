<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\MarketHeaderIndex;
use KoperasiKita\Widgets\MarketsWidget;

/**
 * Koperasi Kita -> Market Header Index: manages the market index
 * strip below the header - enable/disable, add/remove/reorder
 * items, show/hide each. Server-rendered, no admin JS: every
 * button submits the form, and saves run on the load hook (never
 * in render(), which only reads the saved option).
 *
 * The item list is the picked set in picked order; the row order in
 * the form is the strip order. Removing every item falls back to
 * the default set (MarketHeaderIndex::settings), the same empty
 * rule as the Customizer picker.
 */
final class MarketHeaderPage
{
    public const PAGE_SLUG = 'kk-market-header';

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
        ?>
        <div class="wrap kk-market-header-page">
            <h1 class="kk-market-header-title"><?php esc_html_e('Market Header Index', 'koperasikita'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'koperasikita'); ?></p></div>
            <?php endif; ?>

            <section class="kk-market-header-card">
                <p><?php esc_html_e('The market index strip below the header shows the picked market indices - symbol, price and day change - with a snapshot timestamp. Data comes only from the Markets providers; an item with no price is skipped, never invented.', 'koperasikita'); ?></p>

                <form method="post" action="<?php echo esc_url($pageUrl); ?>">
                    <?php wp_nonce_field('kk_market_header', 'kk_market_header_nonce'); ?>

                    <p>
                        <label>
                            <input type="checkbox" name="kk_market_header_enabled" value="1" <?php checked($settings['enabled']); ?>>
                            <?php esc_html_e('Show the market index strip', 'koperasikita'); ?>
                        </label>
                    </p>

                    <p>
                        <label for="kk-market-header-style"><?php esc_html_e('Strip style', 'koperasikita'); ?></label>
                        <select id="kk-market-header-style" name="kk_market_header_style">
                            <option value="dark" <?php selected($settings['style'], 'dark'); ?>><?php esc_html_e('Dark', 'koperasikita'); ?></option>
                            <option value="light" <?php selected($settings['style'], 'light'); ?>><?php esc_html_e('Light', 'koperasikita'); ?></option>
                        </select>
                    </p>

                    <p>
                        <label for="kk-market-header-cycle-interval"><?php esc_html_e('Seconds between items on mobile', 'koperasikita'); ?></label>
                        <input type="number" id="kk-market-header-cycle-interval" name="kk_market_header_cycle_interval" min="2" max="120" step="1" value="<?php echo esc_attr((string) $settings['cycle_interval']); ?>">
                        <span class="description"><?php esc_html_e('On small screens the strip shows one item at a time; this is how long each item shows.', 'koperasikita'); ?></span>
                    </p>

                    <div class="kk-market-header-table-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Item', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Show', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Order', 'koperasikita'); ?></th>
                                    <th scope="col"><?php esc_html_e('Actions', 'koperasikita'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($settings['items'] as $row) : ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html($all[$row['item']] ?? $row['item']); ?>
                                            <input type="hidden" name="kk_market_header_items[]" value="<?php echo esc_attr($row['item']); ?>">
                                        </td>
                                        <td>
                                            <input type="checkbox" name="kk_market_header_shown[]" value="<?php echo esc_attr($row['item']); ?>" <?php checked(!$row['hidden']); ?> aria-label="<?php esc_attr_e('Show', 'koperasikita'); ?>">
                                        </td>
                                        <td>
                                            <button type="submit" name="kk_market_header_action" value="<?php echo esc_attr('up:' . $row['item']); ?>" class="button button-small" aria-label="<?php esc_attr_e('Move up', 'koperasikita'); ?>">&uarr;</button>
                                            <button type="submit" name="kk_market_header_action" value="<?php echo esc_attr('down:' . $row['item']); ?>" class="button button-small" aria-label="<?php esc_attr_e('Move down', 'koperasikita'); ?>">&darr;</button>
                                        </td>
                                        <td>
                                            <button type="submit" name="kk_market_header_action" value="<?php echo esc_attr('remove:' . $row['item']); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Remove this item from the strip?', 'koperasikita')); ?>');"><?php esc_html_e('Remove', 'koperasikita'); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
        </div>
        <?php
    }

    /**
     * Handles the form on the load hook: every submit (save, add,
     * remove, move) persists the current enable/visibility state
     * plus its own action, then redirects so a refresh never
     * resubmits. The up/down/remove buttons carry "verb:item";
     * add takes the item from the select.
     */
    public static function handleActionsOnLoad(): void
    {
        if (!isset($_POST['kk_market_header_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_market_header', 'kk_market_header_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        $enabled = isset($raw['kk_market_header_enabled']) && $raw['kk_market_header_enabled'] === '1';

        $style = isset($raw['kk_market_header_style']) && is_string($raw['kk_market_header_style']) ? $raw['kk_market_header_style'] : MarketHeaderIndex::DEFAULT_STYLE;
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
        } elseif (($verb === 'up' || $verb === 'down') && $target !== '') {
            $index = null;

            foreach ($items as $i => $row) {
                if ($row['item'] === $target) {
                    $index = $i;
                    break;
                }
            }

            if ($index !== null) {
                $swap = $verb === 'up' ? $index - 1 : $index + 1;

                if (isset($items[$swap])) {
                    [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
                }
            }
        }

        MarketHeaderIndex::update([
            'enabled' => $enabled,
            'items' => $items,
            'style' => $style,
            'cycle_interval' => $cycleInterval,
        ]);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }
}

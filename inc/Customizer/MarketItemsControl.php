<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Assets\ViteLoader;
use KoperasiKita\Features\MarketHeaderIndex;
use KoperasiKita\Icons\Icon;
use KoperasiKita\Widgets\MarketsWidget;
use WP_Customize_Control;

/**
 * Ordered market-item picker for the Market Header Index section.
 *
 * The picked set is a JSON array of {item, hidden} pairs in picked
 * order, synced into the hidden input (data-customize-setting-link)
 * on every change - the same JSON-in-a-hidden-input pattern as
 * TermMultiSelectControl. The list is a row per item with move
 * up/down, hide/show and remove buttons; the add select lists the
 * items not picked yet. The <template> row gives the JS the button
 * markup (with server-rendered icons) for rows added after load.
 */
final class MarketItemsControl extends WP_Customize_Control
{
    public function enqueue(): void
    {
        $js = ViteLoader::js('src/ts/customizer-controls.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-controls', $js, ['customize-controls'], KK_VERSION, true);
            wp_localize_script('kk-customizer-controls', 'kkCustomizerControls', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => \KoperasiKita\Api\Ajax::nonce(),
            ]);
        }

        $css = ViteLoader::css('src/css/customizer-controls.css');

        if ($css !== '') {
            wp_enqueue_style('kk-customizer-controls', $css, [], KK_VERSION);
        }
    }

    public function render_content(): void
    {
        $items = MarketHeaderIndex::sanitizeItems($this->value());
        $all = MarketsWidget::allItems();
        $picked = [];

        foreach ($items as $row) {
            $picked[$row['item']] = true;
        }

        $remaining = array_diff_key($all, $picked);
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <div
            class="kk-market-items"
            data-kk-market-items
            data-hide-label="<?php echo esc_attr(__('Hide item', 'koperasikita')); ?>"
            data-show-label="<?php echo esc_attr(__('Show item', 'koperasikita')); ?>"
        >
            <ul class="kk-market-items-list">
                <?php foreach ($items as $row) : ?>
                    <li class="kk-market-items-row<?php echo $row['hidden'] ? ' is-hidden' : ''; ?>" data-item="<?php echo esc_attr($row['item']); ?>" data-hidden="<?php echo $row['hidden'] ? '1' : '0'; ?>">
                        <span class="kk-market-items-label"><?php echo esc_html($all[$row['item']] ?? $row['item']); ?></span>
                        <span class="kk-market-items-actions">
                            <button type="button" class="kk-market-items-up" aria-label="<?php esc_attr_e('Move up', 'koperasikita'); ?>"><?php echo Icon::svg('chevron-up', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                            <button type="button" class="kk-market-items-down" aria-label="<?php esc_attr_e('Move down', 'koperasikita'); ?>"><?php echo Icon::svg('chevron-down', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                            <button type="button" class="kk-market-items-hide" aria-label="<?php echo esc_attr($row['hidden'] ? __('Show item', 'koperasikita') : __('Hide item', 'koperasikita')); ?>">
                                <span class="kk-market-items-eye"><?php echo Icon::svg('eye', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="kk-market-items-eye-off"><?php echo Icon::svg('eye-off', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            </button>
                            <button type="button" class="kk-market-items-remove" aria-label="<?php esc_attr_e('Remove', 'koperasikita'); ?>">&times;</button>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="kk-market-items-empty"<?php echo $items !== [] ? ' hidden' : ''; ?>><?php esc_html_e('No items picked. The default set shows instead.', 'koperasikita'); ?></p>
            <div class="kk-market-items-add">
                <select class="kk-market-items-select" aria-label="<?php esc_attr_e('Add item', 'koperasikita'); ?>">
                    <option value=""><?php esc_html_e('Add item…', 'koperasikita'); ?></option>
                    <?php foreach ($remaining as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button kk-market-items-add-button"><?php esc_html_e('Add', 'koperasikita'); ?></button>
            </div>
            <template class="kk-market-items-template">
                <li class="kk-market-items-row" data-item="" data-hidden="0">
                    <span class="kk-market-items-label"></span>
                    <span class="kk-market-items-actions">
                        <button type="button" class="kk-market-items-up" aria-label="<?php esc_attr_e('Move up', 'koperasikita'); ?>"><?php echo Icon::svg('chevron-up', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="kk-market-items-down" aria-label="<?php esc_attr_e('Move down', 'koperasikita'); ?>"><?php echo Icon::svg('chevron-down', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="kk-market-items-hide" aria-label="<?php esc_attr_e('Hide item', 'koperasikita'); ?>">
                            <span class="kk-market-items-eye"><?php echo Icon::svg('eye', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <span class="kk-market-items-eye-off"><?php echo Icon::svg('eye-off', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        </button>
                        <button type="button" class="kk-market-items-remove" aria-label="<?php esc_attr_e('Remove', 'koperasikita'); ?>">&times;</button>
                    </span>
                </li>
            </template>
            <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr((string) wp_json_encode($items)); ?>">
        </div>
        <?php
    }
}

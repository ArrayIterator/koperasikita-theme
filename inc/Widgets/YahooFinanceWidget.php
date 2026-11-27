<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Yahoo Finance widget: live stock / index prices from Yahoo Finance.
 *
 * Always uses the Yahoo provider (not the configured Markets provider):
 * the default Yahoo set — IHSG (^JKSE), LQ45 (^JKLQ45) and the 12
 * heaviest LQ45 constituents, 14 symbols — live from Yahoo Finance's
 * public spark endpoint, free, no API key. Unofficial and undocumented
 * — Yahoo can change or block it at any time — so a failed fetch falls
 * back to the cached last good value and then to an honest empty state,
 * never an invented number.
 *
 * Layout reuses the Markets widget layouts (Vertical List, Grid,
 * Horizontal Slider, Vertical Slider, Full-width Strip, Marquee),
 * including the Visible-items count for the Horizontal Slider and
 * Marquee. When the Markets feature is disabled the widget renders
 * nothing.
 */
final class YahooFinanceWidget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'kk_yahoo_finance',
            __('Koperasi Kita Yahoo Finance', 'koperasikita'),
            [
                'description' => __('Live IHSG index from Yahoo Finance (free, no API key).', 'koperasikita'),
                'classname' => 'kk-yahoo-finance-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        if (!Markets::isEnabled()) {
            return;
        }

        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $showChange = !isset($instance['show_change']) || (bool) $instance['show_change'];
        $layout = MarketsWidget::sanitizeLayout($instance['layout'] ?? MarketsWidget::LAYOUT_LIST);
        $visible = MarketsWidget::sanitizeVisible($instance['visible'] ?? 1);

        $providers = Markets::providers();
        $yahoo = $providers['yahoo'] ?? null;
        $prices = ($yahoo !== null && $yahoo->supports('stock')) ? $yahoo->prices('stock') : [];

        $markets = [
            'stock' => [
                'label' => Markets::labels()['stock'] ?? __('Stocks', 'koperasikita'),
                'prices' => $prices,
                'client' => ['provider' => 'yahoo'],
            ],
        ];

        echo $args['before_widget'] ?? '';

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        if ($layout === MarketsWidget::LAYOUT_INDEX_STRIP) {
            get_template_part('template-parts/markets/index-strip', null, [
                'items' => array_values($markets['stock']['prices']),
                'show_change' => $showChange,
            ]);
        } elseif ($layout === MarketsWidget::LAYOUT_SLIDER_VERTICAL) {
            get_template_part('template-parts/markets/slider-vertical', null, [
                'markets' => $markets,
                'show_change' => $showChange,
            ]);
        } elseif ($layout === MarketsWidget::LAYOUT_MARQUEE) {
            get_template_part('template-parts/markets/marquee', null, [
                'markets' => $markets,
                'show_change' => $showChange,
                'visible' => $visible,
            ]);
        } else {
            get_template_part('template-parts/markets/strip', null, [
                'markets' => $markets,
                'show_change' => $showChange,
                'layout' => $layout,
                'visible' => $visible,
            ]);
        }

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Yahoo Finance', 'koperasikita');
        $showChange = !isset($instance['show_change']) || (bool) $instance['show_change'];
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                value="<?php echo esc_attr($title); ?>"
            >
        </p>
        <?php WidgetHeader::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>">
                <?php esc_html_e('Layout', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('layout')); ?>"
                name="<?php echo esc_attr($this->get_field_name('layout')); ?>"
            >
                <?php $layout = MarketsWidget::sanitizeLayout($instance['layout'] ?? MarketsWidget::LAYOUT_LIST); ?>
                <?php foreach (MarketsWidget::layoutLabels() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($layout, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('visible')); ?>">
                <?php esc_html_e('Visible items (desktop)', 'koperasikita'); ?>
            </label>
            <input
                class="tiny-text"
                type="number"
                min="1"
                max="4"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('visible')); ?>"
                name="<?php echo esc_attr($this->get_field_name('visible')); ?>"
                value="<?php echo esc_attr((string) MarketsWidget::sanitizeVisible($instance['visible'] ?? 1)); ?>"
            >
            <span class="description"><?php esc_html_e('How many items show at once in the Horizontal Slider and Marquee layouts.', 'koperasikita'); ?></span>
        </p>
        <p>
            <input
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_change')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_change')); ?>"
                value="1"
                <?php checked($showChange); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_change')); ?>">
                <?php esc_html_e('Show day change', 'koperasikita'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $new New settings.
     * @param array<string, mixed> $old Previous settings.
     * @return array<string, mixed>
     */
    public function update($new, $old): array
    {
        $instance = [];
        $instance['title'] = isset($new['title']) ? sanitize_text_field((string) $new['title']) : '';

        // An unchecked checkbox is not submitted at all, so "absent" means off.
        $instance['show_change'] = isset($new['show_change']) && (bool) $new['show_change'];
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance['layout'] = MarketsWidget::sanitizeLayout($new['layout'] ?? MarketsWidget::LAYOUT_LIST);
        $instance['visible'] = MarketsWidget::sanitizeVisible($new['visible'] ?? 1);

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Markets widget: a compact strip with gold spot, stocks and crypto prices.
 *
 * Which markets show is picked in the widget form; the prices themselves
 * come from the provider system (manual snapshots in Settings -> Markets
 * by default). A market with no data renders an honest "no data" line, and
 * when none of the picked markets has data the widget says so instead of
 * showing a blank box. When the Markets feature is disabled the widget
 * renders nothing.
 */
final class MarketsWidget extends WP_Widget
{
    public const LAYOUT_LIST = 'list';
    public const LAYOUT_GRID = 'grid';
    public const LAYOUT_SLIDER_HORIZONTAL = 'slider-horizontal';
    public const LAYOUT_SLIDER_VERTICAL = 'slider-vertical';
    public const LAYOUT_STRIP = 'strip';
    public const LAYOUT_MARQUEE = 'marquee';

    /** @return string[] */
    public static function layouts(): array
    {
        return [
            self::LAYOUT_LIST,
            self::LAYOUT_GRID,
            self::LAYOUT_SLIDER_HORIZONTAL,
            self::LAYOUT_SLIDER_VERTICAL,
            self::LAYOUT_STRIP,
            self::LAYOUT_MARQUEE,
        ];
    }

    public static function sanitizeLayout(mixed $value): string
    {
        $layout = is_string($value) ? $value : '';
        return in_array($layout, self::layouts(), true) ? $layout : self::LAYOUT_LIST;
    }

    /**
     * How many items are visible at once on desktop, for the
     * Horizontal Slider and Marquee layouts. Clamped to 1–4.
     */
    public static function sanitizeVisible(mixed $value): int
    {
        $visible = (int) $value;
        return max(1, min(4, $visible));
    }

    /** @return array<string, string> Value => label. */
    public static function layoutLabels(): array
    {
        return [
            self::LAYOUT_LIST => __('Vertical List', 'koperasikita'),
            self::LAYOUT_GRID => __('Grid', 'koperasikita'),
            self::LAYOUT_SLIDER_HORIZONTAL => __('Horizontal Slider', 'koperasikita'),
            self::LAYOUT_SLIDER_VERTICAL => __('Vertical Slider', 'koperasikita'),
            self::LAYOUT_STRIP => __('Full-width Strip', 'koperasikita'),
            self::LAYOUT_MARQUEE => __('Marquee', 'koperasikita'),
        ];
    }

    public function __construct()
    {
        parent::__construct(
            'kk_markets',
            __('Koperasi Kita Markets', 'koperasikita'),
            [
                'description' => __('Gold, stock and crypto prices from the Markets providers. Prices are edited in Settings -> Markets.', 'koperasikita'),
                'classname' => 'kk-markets-widget',
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
        $selected = isset($instance['markets']) && is_array($instance['markets'])
            ? array_values(array_intersect($instance['markets'], Markets::markets()))
            : Markets::markets();
        $showChange = !isset($instance['show_change']) || (bool) $instance['show_change'];
        $layout = self::sanitizeLayout($instance['layout'] ?? self::LAYOUT_LIST);
        $visible = self::sanitizeVisible($instance['visible'] ?? 1);

        $markets = [];
        foreach ($selected as $market) {
            $markets[$market] = [
                'label' => Markets::labels()[$market],
                'prices' => Markets::prices($market),
            ];
        }

        echo $args['before_widget'] ?? '';

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        if ($layout === self::LAYOUT_SLIDER_VERTICAL) {
            get_template_part('template-parts/markets/slider-vertical', null, [
                'markets' => $markets,
                'show_change' => $showChange,
            ]);
        } elseif ($layout === self::LAYOUT_MARQUEE) {
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
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Markets', 'koperasikita');
        $selected = isset($instance['markets']) && is_array($instance['markets'])
            ? array_values(array_intersect($instance['markets'], Markets::markets()))
            : Markets::markets();
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
                <?php $layout = self::sanitizeLayout($instance['layout'] ?? self::LAYOUT_LIST); ?>
                <?php foreach (self::layoutLabels() as $value => $label) : ?>
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
                value="<?php echo esc_attr((string) self::sanitizeVisible($instance['visible'] ?? 1)); ?>"
            >
            <span class="description"><?php esc_html_e('How many items show at once in the Horizontal Slider and Marquee layouts.', 'koperasikita'); ?></span>
        </p>
        <p><?php esc_html_e('Markets to show:', 'koperasikita'); ?></p>
        <?php foreach (Markets::labels() as $market => $label) : ?>
            <p>
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('markets-' . $market)); ?>"
                    name="<?php echo esc_attr($this->get_field_name('markets')); ?>[]"
                    value="<?php echo esc_attr($market); ?>"
                    <?php checked(in_array($market, $selected, true)); ?>
                >
                <label for="<?php echo esc_attr($this->get_field_id('markets-' . $market)); ?>">
                    <?php echo esc_html($label); ?>
                </label>
            </p>
        <?php endforeach; ?>
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

        $markets = isset($new['markets']) && is_array($new['markets'])
            ? array_values(array_intersect($new['markets'], Markets::markets()))
            : [];
        $instance['markets'] = $markets !== [] ? $markets : Markets::markets();

        // An unchecked checkbox is not submitted at all, so "absent" means off.
        $instance['show_change'] = isset($new['show_change']) && (bool) $new['show_change'];
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance['layout'] = self::sanitizeLayout($new['layout'] ?? self::LAYOUT_LIST);
        $instance['visible'] = self::sanitizeVisible($new['visible'] ?? 1);

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Gold price widget: a vertical auto-scroll slider with the six gold
 * brands (Antam, UBS, Galeri 24, Lotus Archi, King Halim,
 * Emasku (HRTA GOLD)).
 *
 * Prices come from each brand's own provider, picked per brand on the
 * Gold Prices page (Gold::brandPrices); the widget form only picks the
 * title and which brands to show. When the Markets feature is disabled
 * the widget renders nothing, and when no brand has a saved price it
 * renders an honest empty state instead of invented numbers.
 */
final class GoldWidget extends WP_Widget
{
    private const DEFAULT_STYLE = 'vertical-carousel';
    private const DEFAULT_COLOR_STYLE = 'auto';
    private const DEFAULT_AUTOPLAY = true;
    private const DEFAULT_INTERVAL = 5;
    private const MIN_INTERVAL = 2;
    private const MAX_INTERVAL = 30;
    private const DEFAULT_ITEMS_PER_VIEW = 1;
    private const MIN_ITEMS_PER_VIEW = 1;
    private const MAX_ITEMS_PER_VIEW = 3;
    private const STYLES = ['vertical-carousel', 'horizontal-carousel', 'slider-horizontal'];
    private const COLOR_STYLES = ['auto', 'dark', 'light'];

    public function __construct()
    {
        parent::__construct(
            'kk_gold',
            __('Koperasi Kita Gold Prices', 'koperasikita'),
            [
                'description' => __('Gold prices for Indonesian gold brands in an auto-scrolling slider. Prices are edited in Settings -> Markets.', 'koperasikita'),
                'classname' => 'kk-gold-widget',
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
        $brands = isset($instance['brands']) && is_array($instance['brands'])
            ? array_values(array_intersect($instance['brands'], Gold::enabledBrands()))
            : Gold::enabledBrands();
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $colorStyle = self::sanitizeColorStyle(isset($instance['color_style']) ? (string) $instance['color_style'] : self::DEFAULT_COLOR_STYLE);
        $autoplay = !isset($instance['autoplay']) || (bool) $instance['autoplay'];
        $interval = self::sanitizeInterval(isset($instance['interval']) ? (int) $instance['interval'] : self::DEFAULT_INTERVAL);
        $itemsPerView = self::sanitizeItemsPerView(isset($instance['items_per_view']) ? (int) $instance['items_per_view'] : self::DEFAULT_ITEMS_PER_VIEW);
        $showDots = !empty($instance['show_dots']);

        $prices = Gold::brandPrices($brands);

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance));

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        get_template_part('template-parts/gold/slider', null, [
            'brands' => $prices,
            'style' => $style,
            'color_style' => $colorStyle,
            'autoplay' => $autoplay,
            'interval' => $interval,
            'items_per_view' => $itemsPerView,
            'show_dots' => $showDots,
        ]);

        echo $args['after_widget'] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public static function styleOptions(): array
    {
        return [
            'vertical-carousel' => __('Vertical Carousel', 'koperasikita'),
            'horizontal-carousel' => __('Horizontal Carousel', 'koperasikita'),
            'slider-horizontal' => __('Slider Horizontal', 'koperasikita'),
        ];
    }

    public static function sanitizeStyle(string $style): string
    {
        return in_array($style, self::STYLES, true) ? $style : self::DEFAULT_STYLE;
    }

    /**
     * Auto follows the site colour scheme; Dark and Light force the
     * theme's dark and light card colours, the same rule as the
     * Agenda card.
     *
     * @return array<string, string>
     */
    public static function colorStyleOptions(): array
    {
        return [
            'auto' => __('Auto', 'koperasikita'),
            'dark' => __('Dark Green', 'koperasikita'),
            'light' => __('Light', 'koperasikita'),
        ];
    }

    public static function sanitizeColorStyle(string $style): string
    {
        return in_array($style, self::COLOR_STYLES, true) ? $style : self::DEFAULT_COLOR_STYLE;
    }

    public static function sanitizeInterval(int $interval): int
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
    }

    /**
     * @return array<int, string>
     */
    public static function itemsPerViewOptions(): array
    {
        return [
            1 => __('1 item', 'koperasikita'),
            2 => __('2 items', 'koperasikita'),
            3 => __('3 items', 'koperasikita'),
        ];
    }

    public static function sanitizeItemsPerView(int $items): int
    {
        return max(self::MIN_ITEMS_PER_VIEW, min(self::MAX_ITEMS_PER_VIEW, $items));
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Gold Prices', 'koperasikita');
        $selected = isset($instance['brands']) && is_array($instance['brands'])
            ? array_values(array_intersect($instance['brands'], Gold::enabledBrands()))
            : Gold::enabledBrands();
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $colorStyle = self::sanitizeColorStyle(isset($instance['color_style']) ? (string) $instance['color_style'] : self::DEFAULT_COLOR_STYLE);
        $autoplay = !isset($instance['autoplay']) || (bool) $instance['autoplay'];
        $interval = self::sanitizeInterval(isset($instance['interval']) ? (int) $instance['interval'] : self::DEFAULT_INTERVAL);
        $itemsPerView = self::sanitizeItemsPerView(isset($instance['items_per_view']) ? (int) $instance['items_per_view'] : self::DEFAULT_ITEMS_PER_VIEW);
        $showDots = !empty($instance['show_dots']);
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
        <?php WidgetSpacing::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('brands')); ?>">
                <?php esc_html_e('Brands to show:', 'koperasikita'); ?>
            </label>
            <?php /* Select2-style picker (see admin.ts): a searchable multi-select, not a checkbox list. */ ?>
            <select
                multiple
                class="widefat"
                data-kk-term-picker
                id="<?php echo esc_attr($this->get_field_id('brands')); ?>"
                name="<?php echo esc_attr($this->get_field_name('brands')); ?>[]"
                style="min-height: 120px;"
            >
                <?php $allBrands = Gold::brands(); ?>
                <?php foreach (Gold::enabledBrands() as $slug) : ?>
                    <?php $label = $allBrands[$slug] ?? $slug; ?>
                    <option
                        value="<?php echo esc_attr($slug); ?>"
                        <?php selected(in_array($slug, $selected, true)); ?>
                    ><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="description"><?php esc_html_e('Leave empty to show all brands.', 'koperasikita'); ?></span>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                <?php esc_html_e('Slider style', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('style')); ?>"
                name="<?php echo esc_attr($this->get_field_name('style')); ?>"
            >
                <?php foreach (self::styleOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($style, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('items_per_view')); ?>">
                <?php esc_html_e('Items per view', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('items_per_view')); ?>"
                name="<?php echo esc_attr($this->get_field_name('items_per_view')); ?>"
            >
                <?php foreach (self::itemsPerViewOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($itemsPerView, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('color_style')); ?>">
                <?php esc_html_e('Colour style', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('color_style')); ?>"
                name="<?php echo esc_attr($this->get_field_name('color_style')); ?>"
            >
                <?php foreach (self::colorStyleOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($colorStyle, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('autoplay')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('autoplay')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('autoplay')); ?>"
                    value="1"
                    <?php checked($autoplay); ?>
                >
                <?php esc_html_e('Autoplay', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('show_dots')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('show_dots')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('show_dots')); ?>"
                    value="1"
                    <?php checked($showDots); ?>
                >
                <?php esc_html_e('Show dots', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('interval')); ?>">
                <?php esc_html_e('Autoplay interval (seconds)', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="<?php echo esc_attr((string) self::MIN_INTERVAL); ?>"
                max="<?php echo esc_attr((string) self::MAX_INTERVAL); ?>"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('interval')); ?>"
                name="<?php echo esc_attr($this->get_field_name('interval')); ?>"
                value="<?php echo esc_attr((string) $interval); ?>"
            >
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

        $brands = isset($new['brands']) && is_array($new['brands'])
            ? array_values(array_intersect($new['brands'], Gold::enabledBrands()))
            : [];
        $instance['brands'] = $brands !== [] ? $brands : Gold::enabledBrands();
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance['style'] = self::sanitizeStyle(isset($new['style']) ? sanitize_text_field((string) $new['style']) : self::DEFAULT_STYLE);
        $instance['color_style'] = self::sanitizeColorStyle(isset($new['color_style']) ? sanitize_text_field((string) $new['color_style']) : self::DEFAULT_COLOR_STYLE);
        $instance['autoplay'] = !empty($new['autoplay']);
        $instance['interval'] = self::sanitizeInterval(isset($new['interval']) ? (int) $new['interval'] : self::DEFAULT_INTERVAL);
        $instance['items_per_view'] = self::sanitizeItemsPerView(isset($new['items_per_view']) ? (int) $new['items_per_view'] : self::DEFAULT_ITEMS_PER_VIEW);
        $instance['show_dots'] = !empty($new['show_dots']);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

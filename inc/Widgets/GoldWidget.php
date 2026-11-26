<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Gold price widget: a vertical auto-scroll slider with the five gold
 * brands (Antam, UBS, Galeri 24, Pegadaian, EmasKita).
 *
 * Prices come from each brand's own provider, picked per brand on the
 * Gold Prices page (Gold::brandPrices); the widget form only picks the
 * title and which brands to show. When the Markets feature is disabled
 * the widget renders nothing, and when no brand has a saved price it
 * renders an honest empty state instead of invented numbers.
 */
final class GoldWidget extends WP_Widget
{
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

        $prices = Gold::brandPrices($brands);

        echo $args['before_widget'] ?? '';

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        get_template_part('template-parts/gold/slider', null, [
            'brands' => $prices,
        ]);

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Gold Prices', 'koperasikita');
        $selected = isset($instance['brands']) && is_array($instance['brands'])
            ? array_values(array_intersect($instance['brands'], Gold::enabledBrands()))
            : Gold::enabledBrands();
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
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC);

        return $instance;
    }
}

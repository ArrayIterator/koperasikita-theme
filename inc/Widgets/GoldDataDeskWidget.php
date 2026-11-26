<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Gold Data Desk widget: the gold brands as a data table — one row
 * per brand with the price per gram and the last-updated date.
 *
 * Prices come from the Markets snapshot layer (Settings -> Markets);
 * the widget form only picks the title and which brands to show.
 * The rows carry the same data-kk-symbol / data-kk-price hooks as
 * the Markets strip, so the gold-markets script refreshes the
 * prices live. When the Markets feature is disabled the widget
 * renders nothing, and when no brand has a saved price it renders
 * an honest empty state instead of invented numbers.
 */
final class GoldDataDeskWidget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'kk_gold_data_desk',
            __('Koperasi Kita Gold Data Desk', 'koperasikita'),
            [
                'description' => __('Gold prices for Indonesian gold brands as a data table. Prices are edited in Settings -> Markets.', 'koperasikita'),
                'classname' => 'kk-gold-data-desk-widget',
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

        get_template_part('template-parts/gold/data-desk', null, [
            'brands' => $prices,
            'client' => Markets::clientInfo('gold'),
        ]);

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Gold Data Desk', 'koperasikita');
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
        <p><?php esc_html_e('Brands to show:', 'koperasikita'); ?></p>
        <?php $allBrands = Gold::brands(); ?>
        <?php foreach (Gold::enabledBrands() as $slug) : ?>
            <?php $label = $allBrands[$slug] ?? $slug; ?>
            <p>
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('brands-' . $slug)); ?>"
                    name="<?php echo esc_attr($this->get_field_name('brands')); ?>[]"
                    value="<?php echo esc_attr($slug); ?>"
                    <?php checked(in_array($slug, $selected, true)); ?>
                >
                <label for="<?php echo esc_attr($this->get_field_id('brands-' . $slug)); ?>">
                    <?php echo esc_html($label); ?>
                </label>
            </p>
        <?php endforeach; ?>
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

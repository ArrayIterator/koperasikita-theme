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

        $markets = [];
        foreach ($selected as $market) {
            $markets[$market] = [
                'label' => Markets::labels()[$market],
                'prices' => Markets::prices($market),
            ];
        }

        echo $args['before_widget'] ?? '';

        if ($title !== '') {
            echo ($args['before_title'] ?? '') . esc_html($title) . ($args['after_title'] ?? '');
        }

        get_template_part('template-parts/markets/strip', null, [
            'markets' => $markets,
            'show_change' => $showChange,
        ]);

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
        <p class="description">
            <?php
            printf(
                /* translators: %s: URL of the Markets settings screen. */
                esc_html__('Edit the prices in %s.', 'koperasikita'),
                '<a href="' . esc_url(admin_url('options-general.php?page=kk-markets')) . '">' . esc_html__('Settings -> Markets', 'koperasikita') . '</a>'
            );
            ?>
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

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Features;
use KoperasiKita\Features\Weather;
use WP_Widget;

/**
 * Weather widget.
 *
 * Renders the current weather for the configured default city on the
 * server (first paint, cached by the Weather module), then the frontend
 * script re-renders it for the visitor's picked location. When the
 * weather module is disabled the widget outputs nothing at all.
 */
final class WeatherWidget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'kk_weather',
            __('Koperasi Kita Weather', 'koperasikita'),
            ['description' => __('Current weather for a city, with a location picker.', 'koperasikita')]
        );
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $instance
     */
    public function widget($args, $instance): void
    {
        if (!Features::isEnabled('weather')) {
            return;
        }

        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $city = isset($instance['default_city']) && trim((string) $instance['default_city']) !== ''
            ? (string) $instance['default_city']
            : Weather::DEFAULT_CITY;
        $showDetails = !empty($instance['show_details']);

        $location = Weather::resolveCity($city);
        $weather = Weather::current($location);

        echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($title !== '') {
            echo ($args['before_title'] ?? '') . esc_html($title) . ($args['after_title'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        ?>
        <div class="kk-weather-widget" data-kk-weather-widget
            data-default-city="<?php echo esc_attr($location['name']); ?>"
            data-default-latitude="<?php echo esc_attr((string) $location['latitude']); ?>"
            data-default-longitude="<?php echo esc_attr((string) $location['longitude']); ?>"
            data-show-details="<?php echo $showDetails ? '1' : '0'; ?>">
            <p class="kk-weather-place"><?php echo esc_html($location['name']); ?></p>
            <div class="kk-weather-body" data-kk-weather-body>
                <?php
                if ($weather !== null) {
                    echo Weather::renderCurrentHtml($weather, $showDetails); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    ?>
                    <p class="kk-widget-empty"><?php esc_html_e('Weather is unavailable right now.', 'koperasikita'); ?></p>
                    <?php
                }
                ?>
            </div>
            <button type="button" class="kk-button kk-button-ghost kk-weather-change" data-kk-weather-open>
                <?php esc_html_e('Change location', 'koperasikita'); ?>
            </button>
        </div>
        <?php

        echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<string, mixed> $instance
     */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Weather', 'koperasikita');
        $city = isset($instance['default_city']) ? (string) $instance['default_city'] : Weather::DEFAULT_CITY;
        $showDetails = !empty($instance['show_details']);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('default_city')); ?>">
                <?php esc_html_e('Default city', 'koperasikita'); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('default_city')); ?>"
                name="<?php echo esc_attr($this->get_field_name('default_city')); ?>" type="text"
                value="<?php echo esc_attr($city); ?>">
            <span class="description">
                <?php esc_html_e('Shown until a visitor picks another location.', 'koperasikita'); ?>
            </span>
        </p>
        <p>
            <input class="checkbox" id="<?php echo esc_attr($this->get_field_id('show_details')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_details')); ?>" type="checkbox"
                <?php checked($showDetails); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_details')); ?>">
                <?php esc_html_e('Show details (feels like, high/low, humidity, wind)', 'koperasikita'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $newInstance
     * @param array<string, mixed> $oldInstance
     * @return array<string, mixed>
     */
    public function update($newInstance, $oldInstance): array
    {
        $instance = [];

        $instance['title'] = isset($newInstance['title'])
            ? sanitize_text_field((string) $newInstance['title'])
            : '';

        $instance['default_city'] = isset($newInstance['default_city'])
            ? sanitize_text_field((string) $newInstance['default_city'])
            : Weather::DEFAULT_CITY;

        $instance['show_details'] = !empty($newInstance['show_details']) ? 1 : 0;

        return $instance;
    }
}

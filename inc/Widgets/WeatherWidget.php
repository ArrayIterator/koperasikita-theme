<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Features;
use KoperasiKita\Features\Weather;
use WP_Widget;

/**
 * Weather widget.
 *
 * Renders the current weather for the first configured location on
 * the server (first paint, cached by the Weather module), then the
 * frontend script re-renders it for the visitor's picked location.
 * The widget carries a list of locations (not one city): the
 * frontend location picker offers them as quick picks, so a visitor
 * can switch between the cities the widget was set up with. An old
 * instance with only a default city keeps working - the city becomes
 * the single location. When the weather module is disabled the
 * widget outputs nothing at all.
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
        $showDetails = !empty($instance['show_details']);

        $locations = $this->instanceLocations($instance);
        $location = $locations[0];
        $weather = Weather::current($location);

        echo WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        ?>
        <div class="kk-weather-widget" data-kk-weather-widget
            data-default-city="<?php echo esc_attr($location['name']); ?>"
            data-default-latitude="<?php echo esc_attr((string) $location['latitude']); ?>"
            data-default-longitude="<?php echo esc_attr((string) $location['longitude']); ?>"
            data-locations="<?php echo esc_attr(wp_json_encode($locations) ?: '[]'); ?>"
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
     * The widget's locations: the saved list, or the single default
     * city for an old instance that never had a list.
     *
     * @param array<string, mixed> $instance
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    private function instanceLocations(array $instance): array
    {
        $locations = isset($instance['locations']) ? Weather::sanitizeLocations($instance['locations']) : [];

        if ($locations !== []) {
            return $locations;
        }

        $city = isset($instance['default_city']) && trim((string) $instance['default_city']) !== ''
            ? (string) $instance['default_city']
            : Weather::DEFAULT_CITY;

        return [Weather::resolveCity($city)];
    }

    /**
     * @param array<string, mixed> $instance
     */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Weather', 'koperasikita');
        $locationsText = Weather::locationsText($this->instanceLocations($instance));
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
        <?php WidgetHeader::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('locations')); ?>">
                <?php esc_html_e('Locations', 'koperasikita'); ?>
            </label>
            <textarea class="widefat" rows="5" id="<?php echo esc_attr($this->get_field_id('locations')); ?>"
                name="<?php echo esc_attr($this->get_field_name('locations')); ?>"><?php echo esc_textarea($locationsText); ?></textarea>
            <span class="description">
                <?php esc_html_e('One location per line: Name, latitude, longitude. The first location is the default; the rest appear as quick picks in the location picker.', 'koperasikita'); ?>
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

        // Locations arrive two ways: the block editor sends an array
        // of {name, latitude, longitude}; the classic form sends one
        // textarea line per location. Both go through the same
        // sanitizer, so block and classic instances validate alike.
        if (isset($newInstance['locations']) && is_string($newInstance['locations'])) {
            $locations = Weather::parseLocationsText($newInstance['locations']);
        } elseif (isset($newInstance['locations']) && is_array($newInstance['locations'])) {
            $locations = Weather::sanitizeLocations($newInstance['locations']);
        } else {
            $locations = [];
        }

        // Backwards compat: an old instance with only a default city
        // keeps working - the city becomes the single location.
        if ($locations === [] && isset($newInstance['default_city']) && trim((string) $newInstance['default_city']) !== '') {
            $found = Weather::findCity((string) $newInstance['default_city']);
            $locations = $found !== null ? [$found] : [];
        }

        $instance['locations'] = $locations;
        $instance['default_city'] = $locations !== []
            ? $locations[0]['name']
            : (isset($newInstance['default_city']) ? sanitize_text_field((string) $newInstance['default_city']) : Weather::DEFAULT_CITY);

        $instance['show_details'] = !empty($newInstance['show_details']) ? 1 : 0;
        $instance['header_style'] = WidgetHeader::sanitize($newInstance['header_style'] ?? WidgetHeader::CLASSIC_TOP);

        return $instance;
    }
}

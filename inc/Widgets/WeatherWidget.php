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
    private const DEFAULT_STYLE = 'auto';

    /** Allowed widget style values; anything else falls back to 'auto'. */
    private const STYLES = ['auto', 'dark-green', 'light', 'dark'];

    /** Allowed icon-and-temperature layouts; 'left' is the default. */
    private const TEMP_ALIGNS = ['left', 'center', 'between', 'right'];

    /** Allowed temperature-unit settings; 'auto' follows the site unit. */
    private const TEMP_UNITS = ['auto', 'celsius', 'fahrenheit', 'kelvin'];

    public function __construct()
    {
        parent::__construct(
            'kk_weather',
            __('KK: Cuaca', 'koperasikita'),
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
        $showIcon = !isset($instance['show_icon']) || !empty($instance['show_icon']);
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $tempAlign = self::sanitizeTempAlign(isset($instance['temp_align']) ? (string) $instance['temp_align'] : 'left');
        $tempUnit = Weather::resolveTempUnit(isset($instance['temp_unit']) ? (string) $instance['temp_unit'] : 'auto');

        $locations = $this->instanceLocations($instance);
        $location = $locations[0];
        $weather = Weather::current($location);
        $storageKey = $this->storageKey($args);

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        ?>
        <div class="kk-weather-widget<?php echo $style !== self::DEFAULT_STYLE ? ' kk-weather-widget--' . esc_attr($style) : ''; ?><?php echo $tempAlign !== 'left' ? ' kk-weather-widget--temp-' . esc_attr($tempAlign) : ''; ?>" data-kk-weather-widget
            data-weather-key="<?php echo esc_attr($storageKey); ?>"
            data-default-city="<?php echo esc_attr($location['name']); ?>"
            data-default-latitude="<?php echo esc_attr((string) $location['latitude']); ?>"
            data-default-longitude="<?php echo esc_attr((string) $location['longitude']); ?>"
            data-locations="<?php echo esc_attr(wp_json_encode($locations) ?: '[]'); ?>"
            data-show-details="<?php echo $showDetails ? '1' : '0'; ?>"
            data-show-icon="<?php echo $showIcon ? '1' : '0'; ?>"
            data-temp-unit="<?php echo esc_attr($tempUnit); ?>">
            <p class="kk-weather-place" title="<?php echo esc_attr(isset($location['fullName']) && is_string($location['fullName']) && $location['fullName'] !== '' ? $location['fullName'] : $location['name']); ?>"><?php echo esc_html($location['name']); ?></p>
            <div class="kk-weather-body" data-kk-weather-body>
                <?php
                if ($weather !== null) {
                    echo Weather::renderCurrentHtml($weather, $showDetails, $showIcon, $tempUnit); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    ?>
                    <p class="kk-widget-empty"><?php esc_html_e('Weather is unavailable right now.', 'koperasikita'); ?></p>
                    <?php
                }
                ?>
            </div>
            <button type="button" class="kk-weather-change" data-kk-weather-open>
                <?php esc_html_e('Change location', 'koperasikita'); ?>
            </button>
        </div>
        <?php

        echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static int $blockOrdinal = 0;

    /**
     * Per-instance storage key for the visitor's picked location.
     *
     * Classic widgets use their widget id (stable per instance);
     * blocks render through the_widget(), which carries no widget id,
     * so they fall back to a render-order ordinal that stays stable
     * as long as the page layout does not change.
     *
     * @param array<string, mixed> $args
     */
    private function storageKey(array $args): string
    {
        $id = isset($args['widget_id']) && is_string($args['widget_id']) ? trim($args['widget_id']) : '';
        $clean = $id !== '' ? (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $id) : '';
        if ($clean !== '') {
            return $clean;
        }
        $key = 'block-' . self::$blockOrdinal;
        self::$blockOrdinal++;
        return $key;
    }

    /**
     * The style options for the settings select.
     *
     * @return array<string, string>
     */
    public static function styleOptions(): array
    {
        return [
            'auto' => __('Auto', 'koperasikita'),
            'dark-green' => __('Dark Green', 'koperasikita'),
            'light' => __('Light', 'koperasikita'),
            'dark' => __('Dark', 'koperasikita'),
        ];
    }

    /**
     * Clamp a saved style to the allowed list.
     */
    public static function sanitizeStyle(string $style): string
    {
        return in_array($style, self::STYLES, true) ? $style : self::DEFAULT_STYLE;
    }

    /**
     * The icon-and-temperature layout options for the settings select.
     *
     * @return array<string, string>
     */
    public static function tempAlignOptions(): array
    {
        return [
            'left' => __('Left', 'koperasikita'),
            'center' => __('Centered', 'koperasikita'),
            'between' => __('Between', 'koperasikita'),
            'right' => __('Right', 'koperasikita'),
        ];
    }

    /**
     * Clamp a saved temperature layout to the allowed list.
     */
    public static function sanitizeTempAlign(string $align): string
    {
        return in_array($align, self::TEMP_ALIGNS, true) ? $align : 'left';
    }

    /**
     * The temperature-unit options for the settings select. 'auto'
     * follows the site-wide Customizer unit.
     *
     * @return array<string, string>
     */
    public static function tempUnitOptions(): array
    {
        return [
            'auto' => __('Auto (site setting)', 'koperasikita'),
            'celsius' => __('Celsius (°C)', 'koperasikita'),
            'fahrenheit' => __('Fahrenheit (°F)', 'koperasikita'),
            'kelvin' => __('Kelvin (K)', 'koperasikita'),
        ];
    }

    /**
     * Clamp a saved temperature-unit setting to the allowed list.
     */
    public static function sanitizeTempUnit(string $unit): string
    {
        return in_array($unit, self::TEMP_UNITS, true) ? $unit : 'auto';
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
        $showIcon = !isset($instance['show_icon']) || !empty($instance['show_icon']);
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $tempAlign = self::sanitizeTempAlign(isset($instance['temp_align']) ? (string) $instance['temp_align'] : 'left');
        $tempUnit = self::sanitizeTempUnit(isset($instance['temp_unit']) ? (string) $instance['temp_unit'] : 'auto');
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
        <?php WidgetSpacing::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                <?php esc_html_e('Style', 'koperasikita'); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>"
                name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <?php foreach (self::styleOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($style, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('temp_align')); ?>">
                <?php esc_html_e('Temperature layout', 'koperasikita'); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('temp_align')); ?>"
                name="<?php echo esc_attr($this->get_field_name('temp_align')); ?>">
                <?php foreach (self::tempAlignOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($tempAlign, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('temp_unit')); ?>">
                <?php esc_html_e('Temperature unit', 'koperasikita'); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('temp_unit')); ?>"
                name="<?php echo esc_attr($this->get_field_name('temp_unit')); ?>">
                <?php foreach (self::tempUnitOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($tempUnit, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
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
        <p>
            <input class="checkbox" id="<?php echo esc_attr($this->get_field_id('show_icon')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_icon')); ?>" type="checkbox"
                <?php checked($showIcon); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_icon')); ?>">
                <?php esc_html_e('Show condition icon', 'koperasikita'); ?>
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
        $instance['show_icon'] = !empty($newInstance['show_icon']) ? 1 : 0;
        $instance['style'] = self::sanitizeStyle(isset($newInstance['style']) ? (string) $newInstance['style'] : self::DEFAULT_STYLE);
        $instance['temp_align'] = self::sanitizeTempAlign(isset($newInstance['temp_align']) ? (string) $newInstance['temp_align'] : 'left');
        $instance['temp_unit'] = self::sanitizeTempUnit(isset($newInstance['temp_unit']) ? (string) $newInstance['temp_unit'] : 'auto');
        $instance['header_style'] = WidgetHeader::sanitize($newInstance['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($newInstance, $oldInstance));

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Weather module.
 *
 * Keyless by design: the v1 provider is Open-Meteo, which needs no API key
 * and no account. All remote calls go through the server so responses can
 * be cached in transients; the browser never talks to the provider directly.
 *
 * When the module is disabled this class registers nothing at all: no AJAX
 * endpoints, no top-bar weather, no widget output and no weather assets.
 * The frontend bundle is guarded the same way by `kkData.isWeatherEnabled`.
 */
final class Weather
{
    public const DEFAULT_CITY = 'Padang';

    public const DEFAULT_LATITUDE = -0.9471;

    public const DEFAULT_LONGITUDE = 100.4172;

    /** How long a fetched forecast is kept (10 minutes). */
    private const FORECAST_TTL = 600;

    /** How long a geocoding result is kept (one day). */
    private const SEARCH_TTL = 86400;

    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';

    public static function register(): void
    {
        if (!Features::isEnabled('weather')) {
            return;
        }

        add_action('wp_ajax_kk_weather', [self::class, 'ajaxCurrent']);
        add_action('wp_ajax_nopriv_kk_weather', [self::class, 'ajaxCurrent']);
        add_action('wp_ajax_kk_weather_search', [self::class, 'ajaxSearch']);
        add_action('wp_ajax_nopriv_kk_weather_search', [self::class, 'ajaxSearch']);
    }

    /**
     * The fallback location used when the visitor has not picked one.
     *
     * @return array{name: string, latitude: float, longitude: float}
     */
    public static function defaultLocation(): array
    {
        return [
            'name' => self::DEFAULT_CITY,
            'latitude' => self::DEFAULT_LATITUDE,
            'longitude' => self::DEFAULT_LONGITUDE,
        ];
    }

    /**
     * Quick-pick cities shown in the location picker strip.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    public static function quickCities(): array
    {
        return [
            ['name' => 'Padang', 'latitude' => -0.9471, 'longitude' => 100.4172],
            ['name' => 'Jakarta', 'latitude' => -6.2088, 'longitude' => 106.8456],
            ['name' => 'Bandung', 'latitude' => -6.9175, 'longitude' => 107.6191],
            ['name' => 'Surabaya', 'latitude' => -7.2575, 'longitude' => 112.7521],
            ['name' => 'Medan', 'latitude' => 3.5952, 'longitude' => 98.6722],
        ];
    }

    /**
     * Fetch the current weather for a location.
     *
     * Returns null when the provider cannot be reached or answers with
     * unusable data. Callers must render an honest empty state, never
     * invented numbers.
     *
     * @param array{name: string, latitude: float, longitude: float} $location
     * @return array{name: string, latitude: float, longitude: float, temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, wind_direction: int|null, high: float|null, low: float|null, code: int, condition: string}|null
     */
    public static function current(array $location): ?array
    {
        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        $cacheKey = self::cacheKey($latitude, $longitude);

        $cached = get_transient($cacheKey);

        if (is_array($cached) && isset($cached['temperature'], $cached['code'])) {
            $cached['name'] = (string) $location['name'];
            $cached['latitude'] = $latitude;
            $cached['longitude'] = $longitude;

            return $cached;
        }

        $url = add_query_arg(
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m',
                'daily' => 'temperature_2m_max,temperature_2m_min',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ],
            self::FORECAST_URL
        );

        $response = wp_remote_get($url, ['timeout' => 8]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !isset($body['current']['temperature_2m'], $body['current']['weather_code'])) {
            return null;
        }

        $current = $body['current'];
        $code = (int) $current['weather_code'];

        $weather = [
            'name' => (string) $location['name'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'temperature' => (float) $current['temperature_2m'],
            'feels_like' => isset($current['apparent_temperature']) ? (float) $current['apparent_temperature'] : null,
            'humidity' => isset($current['relative_humidity_2m']) ? (int) $current['relative_humidity_2m'] : null,
            'wind_speed' => isset($current['wind_speed_10m']) ? (float) $current['wind_speed_10m'] : null,
            'wind_direction' => isset($current['wind_direction_10m']) ? (int) $current['wind_direction_10m'] : null,
            'high' => isset($body['daily']['temperature_2m_max'][0]) ? (float) $body['daily']['temperature_2m_max'][0] : null,
            'low' => isset($body['daily']['temperature_2m_min'][0]) ? (float) $body['daily']['temperature_2m_min'][0] : null,
            'code' => $code,
            'condition' => self::codeLabel($code),
        ];

        set_transient($cacheKey, $weather, self::FORECAST_TTL);

        return $weather;
    }

    /**
     * Search places by name through the provider geocoding endpoint.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float, country: string}>
     */
    public static function search(string $query): array
    {
        $query = trim(sanitize_text_field($query));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $cacheKey = 'kk_weather_search_' . md5(mb_strtolower($query));
        $cached = get_transient($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $url = add_query_arg(
            [
                'name' => $query,
                'count' => 5,
                'language' => 'en',
                'format' => 'json',
            ],
            self::GEOCODING_URL
        );

        $response = wp_remote_get($url, ['timeout' => 8]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body) || empty($body['results']) || !is_array($body['results'])) {
            return [];
        }

        $results = [];

        foreach ($body['results'] as $place) {
            if (!isset($place['name'], $place['latitude'], $place['longitude'])) {
                continue;
            }

            $results[] = [
                'name' => sanitize_text_field((string) $place['name']),
                'latitude' => (float) $place['latitude'],
                'longitude' => (float) $place['longitude'],
                'country' => isset($place['country']) ? sanitize_text_field((string) $place['country']) : '',
            ];
        }

        set_transient($cacheKey, $results, self::SEARCH_TTL);

        return $results;
    }

    /**
     * Human-readable label for a WMO weather code.
     */
    public static function codeLabel(int $code): string
    {
        $labels = [
            0 => 'Clear sky',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45 => 'Fog',
            48 => 'Depositing rime fog',
            51 => 'Light drizzle',
            53 => 'Drizzle',
            55 => 'Dense drizzle',
            56 => 'Light freezing drizzle',
            57 => 'Dense freezing drizzle',
            61 => 'Slight rain',
            63 => 'Rain',
            65 => 'Heavy rain',
            66 => 'Light freezing rain',
            67 => 'Heavy freezing rain',
            71 => 'Slight snow',
            73 => 'Snow',
            75 => 'Heavy snow',
            77 => 'Snow grains',
            80 => 'Slight rain showers',
            81 => 'Rain showers',
            82 => 'Violent rain showers',
            85 => 'Slight snow showers',
            86 => 'Heavy snow showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with slight hail',
            99 => 'Thunderstorm with heavy hail',
        ];

        return $labels[$code] ?? 'Unknown conditions';
    }

    /**
     * Render the inner markup for one fetched forecast.
     *
     * The widget uses this for its server-side first paint; the frontend
     * script re-renders the same shape when the visitor picks another
     * location. Keep the two in sync.
     *
     * @param array{temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, high: float|null, low: float|null, condition: string} $weather
     */
    public static function renderCurrentHtml(array $weather, bool $showDetails): string
    {
        $temperature = number_format_i18n((float) $weather['temperature'], 0);

        $html = '<p class="kk-weather-temp">' . esc_html($temperature) . '<span aria-hidden="true">&deg;C</span></p>';
        $html .= '<p class="kk-weather-condition">' . esc_html((string) $weather['condition']) . '</p>';

        if ($showDetails) {
            $details = [];

            if ($weather['feels_like'] !== null) {
                $details[] = '<li><span>' . esc_html__('Feels like', 'koperasikita') . '</span><strong>'
                    . esc_html(number_format_i18n((float) $weather['feels_like'], 0)) . '&deg;C</strong></li>';
            }

            if ($weather['high'] !== null && $weather['low'] !== null) {
                $details[] = '<li><span>' . esc_html__('High / Low', 'koperasikita') . '</span><strong>'
                    . esc_html(number_format_i18n((float) $weather['high'], 0)) . '&deg; / '
                    . esc_html(number_format_i18n((float) $weather['low'], 0)) . '&deg;C</strong></li>';
            }

            if ($weather['humidity'] !== null) {
                $details[] = '<li><span>' . esc_html__('Humidity', 'koperasikita') . '</span><strong>'
                    . esc_html(number_format_i18n((int) $weather['humidity'])) . '%</strong></li>';
            }

            if ($weather['wind_speed'] !== null) {
                $details[] = '<li><span>' . esc_html__('Wind', 'koperasikita') . '</span><strong>'
                    . esc_html(number_format_i18n((float) $weather['wind_speed'], 0)) . ' km/h</strong></li>';
            }

            if ($details !== []) {
                $html .= '<ul class="kk-weather-details">' . implode('', $details) . '</ul>';
            }
        }

        return $html;
    }

    /**
     * Resolve a widget's configured city name to coordinates.
     *
     * Quick-pick cities resolve locally; anything else goes through the
     * geocoder and falls back to the default city when it cannot be found.
     *
     * @return array{name: string, latitude: float, longitude: float}
     */
    public static function resolveCity(string $city): array
    {
        $city = trim($city);

        if ($city === '') {
            return self::defaultLocation();
        }

        foreach (self::quickCities() as $quick) {
            if (mb_strtolower($quick['name']) === mb_strtolower($city)) {
                return $quick;
            }
        }

        $results = self::search($city);

        if ($results !== []) {
            return [
                'name' => $results[0]['name'],
                'latitude' => $results[0]['latitude'],
                'longitude' => $results[0]['longitude'],
            ];
        }

        return self::defaultLocation();
    }

    /**
     * AJAX: current weather for coordinates (or a city name).
     */
    public static function ajaxCurrent(): void
    {
        check_ajax_referer('koperasikita_nonce', 'nonce');

        $coordinates = self::validateCoordinates(
            isset($_POST['latitude']) ? wp_unslash($_POST['latitude']) : null,
            isset($_POST['longitude']) ? wp_unslash($_POST['longitude']) : null
        );

        if ($coordinates !== null) {
            $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $location = [
                'name' => $name !== '' ? $name : self::DEFAULT_CITY,
                'latitude' => $coordinates[0],
                'longitude' => $coordinates[1],
            ];
        } else {
            $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
            $location = self::resolveCity($city);
        }

        $weather = self::current($location);

        if ($weather === null) {
            wp_send_json_error(
                ['message' => __('Weather is unavailable right now. Please try again later.', 'koperasikita')],
                503
            );
        }

        wp_send_json_success(['weather' => $weather]);
    }

    /**
     * AJAX: place search for the location picker.
     */
    public static function ajaxSearch(): void
    {
        check_ajax_referer('koperasikita_nonce', 'nonce');

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        wp_send_json_success(['results' => self::search($query)]);
    }

    /**
     * Validate raw latitude/longitude input. Returns [lat, lon] or null.
     *
     * @param mixed $latitude
     * @param mixed $longitude
     * @return array{0: float, 1: float}|null
     */
    public static function validateCoordinates($latitude, $longitude): ?array
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return null;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (!is_finite($latitude) || !is_finite($longitude)) {
            return null;
        }

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        return [$latitude, $longitude];
    }

    private static function cacheKey(float $latitude, float $longitude): string
    {
        return 'kk_weather_' . md5(round($latitude, 2) . ',' . round($longitude, 2));
    }
}

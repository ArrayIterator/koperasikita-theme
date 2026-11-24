<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Hooks;
use KoperasiKita\Api\Response;
use KoperasiKita\Providers\OpenMeteoProvider;
use KoperasiKita\Providers\WeatherProvider;

/**
 * Weather module — the façade.
 *
 * This class owns the module's public surface: the default location,
 * the quick-pick cities, city resolution, the WMO code labels, the
 * widget HTML and the AJAX handlers. All fetching lives in the
 * WeatherProvider implementations (the built-in default is the keyless
 * Open-Meteo provider); third parties replace or extend it through the
 * `koperasikita/weather/providers` filter.
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

    public static function isEnabled(): bool
    {
        return Features::isEnabled('weather');
    }

    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        Ajax::add('kk_weather', [self::class, 'ajaxCurrent'], true);
        Ajax::add('kk_weather_search', [self::class, 'ajaxSearch'], true);
    }

    /**
     * Registered weather providers, keyed by id. Third parties plug in
     * through the `koperasikita/weather/providers` filter; entries that
     * do not implement WeatherProvider are dropped.
     *
     * @return array<string, WeatherProvider>
     */
    public static function providers(): array
    {
        $providers = apply_filters(Hooks::WEATHER_PROVIDERS, [
            'open-meteo' => new OpenMeteoProvider(),
        ]);

        if (!is_array($providers)) {
            return ['open-meteo' => new OpenMeteoProvider()];
        }

        $valid = [];

        foreach ($providers as $id => $provider) {
            if ($provider instanceof WeatherProvider && is_string($id) && $id !== '') {
                $valid[$id] = $provider;
            }
        }

        if ($valid === []) {
            return ['open-meteo' => new OpenMeteoProvider()];
        }

        return $valid;
    }

    /**
     * The provider that answers requests: `open-meteo` when present,
     * otherwise the first registered provider.
     */
    public static function provider(): WeatherProvider
    {
        $providers = self::providers();

        if (isset($providers['open-meteo'])) {
            return $providers['open-meteo'];
        }

        return reset($providers);
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
     * Quick-pick cities shown in the location picker strip. Third
     * parties may replace the list through the
     * `koperasikita/weather/quick_cities` filter.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    public static function quickCities(): array
    {
        $cities = apply_filters(Hooks::WEATHER_QUICK_CITIES, [
            ['name' => 'Padang', 'latitude' => -0.9471, 'longitude' => 100.4172],
            ['name' => 'Jakarta', 'latitude' => -6.2088, 'longitude' => 106.8456],
            ['name' => 'Bandung', 'latitude' => -6.9175, 'longitude' => 107.6191],
            ['name' => 'Surabaya', 'latitude' => -7.2575, 'longitude' => 112.7521],
            ['name' => 'Medan', 'latitude' => 3.5952, 'longitude' => 98.6722],
        ]);

        if (!is_array($cities)) {
            return [];
        }

        $clean = [];

        foreach ($cities as $city) {
            if (!is_array($city) || !isset($city['name'], $city['latitude'], $city['longitude'])) {
                continue;
            }

            $coords = self::validateCoordinates($city['latitude'], $city['longitude']);

            if ($coords === null) {
                continue;
            }

            $name = trim((string) $city['name']);

            if ($name === '') {
                continue;
            }

            $clean[] = [
                'name' => $name,
                'latitude' => $coords[0],
                'longitude' => $coords[1],
            ];
        }

        return $clean;
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
        return self::provider()->current($location);
    }

    /**
     * Search places by name through the provider geocoding endpoint.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float, country: string}>
     */
    public static function search(string $query): array
    {
        return self::provider()->search($query);
    }

    /**
     * Human-readable label for a WMO weather code.
     */
    public static function codeLabel(int $code): string
    {
        return OpenMeteoProvider::codeLabel($code);
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
     * Resolve a city name to coordinates, or null when it cannot be
     * found anywhere.
     *
     * Quick-pick cities resolve locally; anything else goes through
     * the provider geocoder. Unlike resolveCity() this never falls
     * back to the default city, so callers that must tell "unknown
     * city" apart from "Padang" (the REST route) can do so.
     *
     * @return array{name: string, latitude: float, longitude: float}|null
     */
    public static function findCity(string $city): ?array
    {
        $city = trim($city);

        if ($city === '') {
            return null;
        }

        foreach (self::quickCities() as $quick) {
            if (mb_strtolower($quick['name']) === mb_strtolower($city)) {
                return $quick;
            }
        }

        $results = self::search($city);

        if ($results === []) {
            return null;
        }

        return [
            'name' => $results[0]['name'],
            'latitude' => $results[0]['latitude'],
            'longitude' => $results[0]['longitude'],
        ];
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
        return self::findCity($city) ?? self::defaultLocation();
    }

    /**
     * AJAX: current weather for coordinates (or a city name).
     *
     * Called through Api\Ajax, which already verified the nonce.
     * Returns the success `data` array, or throws on upstream failure.
     *
     * @return array{weather: array{name: string, latitude: float, longitude: float, temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, wind_direction: int|null, high: float|null, low: float|null, code: int, condition: string}}
     */
    public static function ajaxCurrent(): array
    {
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
            throw new ApiException(
                Response::ERR_UPSTREAM_FAILED,
                __('Weather is unavailable right now. Please try again later.', 'koperasikita'),
                503
            );
        }

        return ['weather' => $weather];
    }

    /**
     * AJAX: place search for the location picker.
     *
     * Called through Api\Ajax, which already verified the nonce.
     *
     * @return array{results: array<int, array{name: string, latitude: float, longitude: float, country: string}>}
     */
    public static function ajaxSearch(): array
    {
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        return ['results' => self::search($query)];
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
}

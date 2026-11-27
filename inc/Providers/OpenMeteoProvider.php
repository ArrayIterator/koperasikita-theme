<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Open-Meteo weather provider (keyless, no account needed).
 *
 * All remote calls go through the server so responses can be cached in
 * transients; the browser never talks to the provider directly. A failed
 * fetch answers with null (current) or an empty array (search) — never
 * an exception and never invented numbers.
 */
final class OpenMeteoProvider implements WeatherProvider
{
    /** How long a fetched forecast is kept (10 minutes). */
    private const FORECAST_TTL = 600;

    /** How long a geocoding result is kept (one day). */
    private const SEARCH_TTL = 86400;

    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';

    public function id(): string
    {
        return 'open-meteo';
    }

    public function label(): string
    {
        return 'Open-Meteo';
    }

    /**
     * @param array{name: string, latitude: float, longitude: float} $location
     * @return array{name: string, latitude: float, longitude: float, temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, wind_direction: int|null, high: float|null, low: float|null, code: int, condition: string}|null
     */
    public function current(array $location): ?array
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
     * @return array<int, array{name: string, latitude: float, longitude: float, country: string}>
     */
    public function search(string $query): array
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
                'country' => self::placeLabel($place),
            ];
        }

        set_transient($cacheKey, $results, self::SEARCH_TTL);

        return $results;
    }

    /**
     * The label under a place name: the finest admin area first, so
     * same-named places in different regencies read differently
     * ("Kediri, East Java, Indonesia"). Empty parts are skipped, so
     * a place with no admin area is just its country, as before.
     */
    private static function placeLabel(array $place): string
    {
        $parts = array_filter([
            isset($place['admin2']) ? sanitize_text_field((string) $place['admin2']) : '',
            isset($place['admin1']) ? sanitize_text_field((string) $place['admin1']) : '',
            isset($place['country']) ? sanitize_text_field((string) $place['country']) : '',
        ]);

        return implode(', ', $parts);
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

    private static function cacheKey(float $latitude, float $longitude): string
    {
        return 'kk_weather_' . md5(round($latitude, 2) . ',' . round($longitude, 2));
    }
}

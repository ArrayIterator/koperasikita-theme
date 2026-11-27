<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * Contract for anything that can supply weather data.
 *
 * Third-party providers plug in through the `koperasikita/weather/providers`
 * filter, which receives an array of WeatherProvider keyed by provider id.
 * The built-in default is the keyless Open-Meteo provider.
 *
 * Rules every implementation must follow:
 *  - `current()` returns null when the provider cannot be reached or
 *    answers with unusable data. Callers render an honest empty state,
 *    never invented numbers.
 *  - `search()` returns an empty array when nothing matches or the
 *    provider fails - never an exception for a bad query.
 *  - Coordinates are validated by the caller (Weather::validateCoordinates);
 *    a provider may still defensively ignore out-of-range input.
 *  - A provider owns its own caching (transients) and its own timeouts.
 *
 * The weather shape:
 * `array{name: string, latitude: float, longitude: float, temperature: float,
 *   feels_like: float|null, humidity: int|null, wind_speed: float|null,
 *   wind_direction: int|null, high: float|null, low: float|null,
 *   code: int, condition: string}`
 */
interface WeatherProvider
{
    public function id(): string;

    public function label(): string;

    /**
     * Current weather for a location.
     *
     * @param array{name: string, latitude: float, longitude: float} $location
     * @return array{name: string, latitude: float, longitude: float, temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, wind_direction: int|null, high: float|null, low: float|null, code: int, condition: string}|null
     */
    public function current(array $location): ?array;

    /**
     * Search places by name.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float, country: string}>
     */
    public function search(string $query): array;
}

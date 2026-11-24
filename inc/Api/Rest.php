<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Markets;
use KoperasiKita\Features\Weather;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The public REST API, namespace `koperasikita/v1`.
 *
 * Read-only: every route returns data, never writes it. Every route
 * has an explicit permission callback — the public routes return true
 * by design because the data they expose (market snapshots, gold
 * brand names, weather for a place) is already shown to anonymous
 * visitors on the public site; the fonts route is limited to users
 * who can edit the theme, because download state is an admin detail.
 *
 * All responses use the unified envelope (see Response): success
 * responses carry the data under `data`, errors under `error` with a
 * stable code. A disabled feature is a 404, an upstream failure is
 * a 503 — an empty result is an empty array, never fake data.
 */
final class Rest
{
    /**
     * @return array<string, mixed>
     */
    public static function allowPublic(): bool
    {
        // The data below is already public on the front page
        // (market strip, gold slider, weather widget), so there is
        // nothing here an anonymous visitor could not see.
        return true;
    }

    public static function register(): void
    {
        register_rest_route(Api::REST_NAMESPACE, '/markets', [
            'methods' => 'GET',
            'callback' => [self::class, 'markets'],
            'permission_callback' => [self::class, 'allowPublic'],
        ]);

        register_rest_route(Api::REST_NAMESPACE, '/markets/(?P<market>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'market'],
            'permission_callback' => [self::class, 'allowPublic'],
            'args' => [
                'market' => [
                    'description' => 'Market slug: crypto, gold or stocks.',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route(Api::REST_NAMESPACE, '/gold', [
            'methods' => 'GET',
            'callback' => [self::class, 'gold'],
            'permission_callback' => [self::class, 'allowPublic'],
        ]);

        register_rest_route(Api::REST_NAMESPACE, '/weather', [
            'methods' => 'GET',
            'callback' => [self::class, 'weather'],
            'permission_callback' => [self::class, 'allowPublic'],
            'args' => [
                'lat' => [
                    'description' => 'Latitude, -90 to 90.',
                    'type' => 'number',
                    'sanitize_callback' => 'floatval',
                ],
                'lon' => [
                    'description' => 'Longitude, -180 to 180.',
                    'type' => 'number',
                    'sanitize_callback' => 'floatval',
                ],
                'city' => [
                    'description' => 'City name; resolved against the known city list.',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(Api::REST_NAMESPACE, '/fonts', [
            'methods' => 'GET',
            'callback' => [self::class, 'fonts'],
            'permission_callback' => static fn (): bool => current_user_can('edit_theme_options'),
        ]);
    }

    /**
     * GET /markets — every enabled market with its prices.
     */
    public static function markets(): WP_REST_Response
    {
        if (!Markets::isEnabled()) {
            return Response::toRest(
                new ApiException(Response::ERR_NOT_FOUND, __('Markets are disabled.', 'koperasikita'), 404)
            );
        }

        return Response::restSuccess(self::marketPrices());
    }

    /**
     * GET /markets/{market} — one market (crypto, gold or stocks).
     */
    public static function market(WP_REST_Request $request): WP_REST_Response
    {
        if (!Markets::isEnabled()) {
            return Response::toRest(
                new ApiException(Response::ERR_NOT_FOUND, __('Markets are disabled.', 'koperasikita'), 404)
            );
        }

        $market = (string) $request->get_param('market');
        $prices = self::marketPrices();

        if (!isset($prices[$market])) {
            return Response::toRest(
                new ApiException(Response::ERR_NOT_FOUND, __('Unknown market.', 'koperasikita'), 404)
            );
        }

        return Response::restSuccess([
            'market' => $market,
            'prices' => $prices[$market],
        ]);
    }

    /**
     * GET /gold — the gold brands and their prices per gram, where a
     * price has been entered. Brands without a snapshot are not
     * listed, so an empty result means "no prices saved", not "gold
     * is free".
     */
    public static function gold(): WP_REST_Response
    {
        if (!Markets::isEnabled()) {
            return Response::toRest(
                new ApiException(Response::ERR_NOT_FOUND, __('Markets are disabled.', 'koperasikita'), 404)
            );
        }

        $prices = Markets::prices('gold');

        return Response::restSuccess([
            'market' => 'gold',
            'prices' => array_map(
                static fn ($price) => $price->toArray(),
                $prices
            ),
        ]);
    }

    /**
     * GET /weather?lat=&lon= or ?city= — current weather for a place.
     * No coordinates and no city means the default location. Only one
     * of lat/lon is a 400, out-of-range coordinates are a 400, a city
     * that cannot be found is a 400, and an upstream failure is a 503.
     */
    public static function weather(WP_REST_Request $request): WP_REST_Response
    {
        if (!Weather::isEnabled()) {
            return Response::toRest(
                new ApiException(Response::ERR_NOT_FOUND, __('Weather is disabled.', 'koperasikita'), 404)
            );
        }

        $lat = $request->get_param('lat');
        $lon = $request->get_param('lon');
        $city = $request->get_param('city');

        if ($lat !== null || $lon !== null) {
            if ($lat === null || $lon === null) {
                return Response::toRest(
                    new ApiException(Response::ERR_INVALID_REQUEST, __('Both latitude and longitude are required.', 'koperasikita'), 400)
                );
            }

            $coordinates = Weather::validateCoordinates((float) $lat, (float) $lon);

            if ($coordinates === null) {
                return Response::toRest(
                    new ApiException(Response::ERR_INVALID_REQUEST, __('Coordinates are out of range.', 'koperasikita'), 400)
                );
            }

            $location = [
                'name' => 'Custom location',
                'latitude' => $coordinates[0],
                'longitude' => $coordinates[1],
            ];
        } elseif (is_string($city) && $city !== '') {
            $location = Weather::findCity($city);

            if ($location === null) {
                return Response::toRest(
                    new ApiException(Response::ERR_INVALID_REQUEST, __('Unknown city.', 'koperasikita'), 400)
                );
            }
        } else {
            $location = Weather::defaultLocation();
        }

        $data = Weather::current($location);

        if ($data === null) {
            return Response::toRest(
                new ApiException(Response::ERR_UPSTREAM_FAILED, __('Weather service unavailable.', 'koperasikita'), 503)
            );
        }

        return Response::restSuccess($data);
    }

    /**
     * GET /fonts — every catalogue font with its download state.
     * Admin only: download state is a site-management detail, not
     * public content.
     */
    public static function fonts(): WP_REST_Response
    {
        $fonts = [];

        foreach (Fonts::catalogue() as $role => $entries) {
            foreach ($entries as $id => $entry) {
                $fonts[$id] = [
                    'label' => (string) $entry['label'],
                    'type' => (string) $entry['type'],
                    'role' => $role,
                    'status' => (string) (Fonts::state($id)['status'] ?? 'not-downloaded'),
                    'cssUrl' => Fonts::localCssUrl($id),
                ];
            }
        }

        return Response::restSuccess(['fonts' => $fonts]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function marketPrices(): array
    {
        $prices = [];

        foreach (Markets::markets() as $market) {
            $prices[$market] = array_map(
                static fn ($price) => $price->toArray(),
                Markets::prices($market)
            );
        }

        return $prices;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

/**
 * The theme's public API surface, versioned as one unit.
 *
 * v1 covers: the `koperasikita/` hook prefix (see Hooks), the provider
 * contracts (see KoperasiKita\Providers), the AJAX endpoints (see Ajax)
 * and the `koperasikita/v1` REST namespace (see Rest). All of them share
 * the Response envelope and the error codes in Response.
 *
 * The version is a single number. A future v2 lives beside v1 (new REST
 * namespace, new hook names where the shape changed) - v1 is never
 * silently redefined.
 */
final class Api
{
    /**
     * Current public API version. Bump only when a public shape changes
     * in a way old clients cannot tolerate.
     */
    public const VERSION = '1';

    /**
     * REST namespace for this version, e.g. `koperasikita/v1`.
     */
    public const REST_NAMESPACE = 'koperasikita/v1';

    /**
     * The one prefix every custom hook in the theme starts with.
     */
    public const HOOK_PREFIX = 'koperasikita/';

    /**
     * Wire the REST routes. AJAX endpoints register themselves from
     * their own feature classes through Ajax::add(), so there is no
     * central AJAX list to keep in sync.
     */
    public static function register(): void
    {
        add_action('rest_api_init', [Rest::class, 'register']);
    }
}

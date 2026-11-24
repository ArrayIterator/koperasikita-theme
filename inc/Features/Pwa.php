<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Assets\ViteLoader;

/**
 * Progressive Web App: manifest + service worker + install prompt.
 *
 * Both endpoints are served by the theme itself through rewrite rules and
 * a query var — there are no static manifest / service-worker files in
 * the theme root, so no plugin, redirect or deployment step can break
 * the URLs. Pretty URLs are used when permalinks are enabled
 * (/manifest.webmanifest, /kk-service-worker.js); with plain permalinks
 * the same endpoints answer on query strings (?kk_pwa=manifest|sw).
 *
 * When the feature is disabled in the Customizer this class registers
 * nothing at all: no rewrite rules, no manifest link, no service-worker
 * registration, no install button and no install prompt. The frontend
 * bundle is guarded the same way by `kkData.isPwaEnabled`.
 *
 * The service worker only ever caches the theme's own public static
 * assets (CSS/JS/images/fonts, same origin, GET only). It never caches
 * admin pages, the login page, AJAX endpoints or navigation responses,
 * so an authenticated or personalised response can never be served back
 * from the cache. Offline, a failed navigation falls back to the
 * anonymously precached home page. Registration failure is swallowed
 * on purpose: the site must keep working exactly as before, just
 * without offline caching.
 */
final class Pwa
{
    public const THEME_COLOR = '#0B6B3A';

    public const BACKGROUND_COLOR = '#FFFFFF';

    public const MANIFEST_SLUG = 'manifest.webmanifest';

    public const SERVICE_WORKER_SLUG = 'kk-service-worker.js';

    private const QUERY_VAR = 'kk_pwa';

    public static function isEnabled(): bool
    {
        return Features::isEnabled('pwa');
    }

    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote(self::MANIFEST_SLUG, '/') . '$',
            'index.php?' . self::QUERY_VAR . '=manifest',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote(self::SERVICE_WORKER_SLUG, '/') . '$',
            'index.php?' . self::QUERY_VAR . '=sw',
            'top'
        );
        add_filter('query_vars', [self::class, 'registerQueryVar']);
        add_action('template_redirect', [self::class, 'serveEndpoint']);
        add_action('wp_head', [self::class, 'printManifestLink']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_footer', [self::class, 'printInstallPrompt']);
        add_action('after_switch_theme', 'flush_rewrite_rules');
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function registerQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * Public URL of the Web App Manifest.
     */
    public static function manifestUrl(): string
    {
        if (get_option('permalink_structure') === '') {
            return add_query_arg(self::QUERY_VAR, 'manifest', home_url('/'));
        }

        return home_url(self::MANIFEST_SLUG);
    }

    /**
     * Public URL of the service worker. The worker must live at the site
     * root (or as close to it as the install allows) so its scope covers
     * the whole site.
     */
    public static function serviceWorkerUrl(): string
    {
        if (get_option('permalink_structure') === '') {
            return add_query_arg(self::QUERY_VAR, 'sw', home_url('/'));
        }

        return home_url(self::SERVICE_WORKER_SLUG);
    }

    public static function enqueueAssets(): void
    {
        $js = ViteLoader::js('src/ts/pwa.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-pwa', $js, ['kk-main'], KK_VERSION, true);
        }

        $css = ViteLoader::css('src/css/pwa.css');

        if ($css !== '') {
            wp_enqueue_style('kk-pwa', $css, ['kk-app'], KK_VERSION);
        }
    }

    /**
     * Manifest link (+ theme colour) in <head>. The install prompt and
     * the service worker both depend on this link existing.
     */
    public static function printManifestLink(): void
    {
        echo '<link rel="manifest" href="' . esc_url(self::manifestUrl()) . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr(self::THEME_COLOR) . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="'
            . esc_url(get_template_directory_uri() . '/assets/img/logo-192.png') . '">' . "\n";
    }

    /**
     * The install prompt modal is a template part, rendered once in the
     * footer. main.ts owns showing it when beforeinstallprompt fires;
     * this only makes sure the markup exists on the page.
     */
    public static function printInstallPrompt(): void
    {
        locate_template('template-parts/pwa/install-prompt.php', true, false);
    }

    /**
     * Serve the manifest or the service worker and stop. Anything else
     * falls through to WordPress untouched.
     */
    public static function serveEndpoint(): void
    {
        $endpoint = get_query_var(self::QUERY_VAR);

        if ($endpoint !== 'manifest' && $endpoint !== 'sw') {
            return;
        }

        if (!self::isEnabled()) {
            status_header(404);
            exit;
        }

        if ($endpoint === 'manifest') {
            self::serveManifest();
        }

        self::serveServiceWorker();
    }

    private static function serveManifest(): void
    {
        $iconsBase = get_template_directory_uri() . '/assets/img/';

        $manifest = [
            'id' => home_url('/'),
            'name' => 'Koperasi Kita',
            'short_name' => 'Koperasi Kita',
            'description' => 'News, announcements and market updates from Koperasi Kita.',
            'start_url' => home_url('/'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => self::BACKGROUND_COLOR,
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                [
                    'src' => $iconsBase . 'logo-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $iconsBase . 'logo-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ],
        ];

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private static function serveServiceWorker(): void
    {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        header('X-Content-Type-Options: nosniff');

        echo str_replace('__KK_CACHE_NAME__', self::cacheName(), self::serviceWorkerScript());
        exit;
    }

    /**
     * Versioned cache name: bumping the theme version retires the old
     * cache on the next service-worker activation, so updates land
     * instead of serving stale assets forever.
     */
    private static function cacheName(): string
    {
        $version = defined('KK_VERSION') ? (string) KK_VERSION : '1.0.0';

        return 'koperasikita-' . $version;
    }

    /**
     * The worker itself. Kept as a string so it can be served from the
     * theme endpoint (with the right content type) instead of living as
     * a static file in the theme root.
     */
    private static function serviceWorkerScript(): string
    {
        return <<<'JS'
'use strict';

/* Koperasi Kita service worker.
 *
 * Static assets (same origin, GET only): cache-first. Everything else
 * passes straight through to the network. Admin pages, the login page
 * and AJAX endpoints are never cached, and navigation responses are
 * never cached either — an offline navigation falls back to the home
 * page, which is precached anonymously (no credentials) at install
 * time, so no authenticated response can ever be served from cache.
 * Nothing here throws: a failed cache write is ignored and the
 * network response is still returned.
 */

const CACHE_NAME = '__KK_CACHE_NAME__';
const STATIC_DESTINATIONS = new Set(['style', 'script', 'image', 'font']);

function isStaticAsset(request, url) {
	if (request.method !== 'GET') {
		return false;
	}
	if (url.origin !== self.location.origin) {
		return false;
	}
	if (url.pathname.indexOf('/wp-admin/') === 0) {
		return false;
	}
	if (url.pathname === '/wp-login.php') {
		return false;
	}
	if (url.pathname.indexOf('admin-ajax.php') !== -1) {
		return false;
	}
	return STATIC_DESTINATIONS.has(request.destination);
}

function offlineResponse() {
	return new Response(
		'<!doctype html><html lang="en"><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			'<title>Koperasi Kita — Offline</title>' +
			'<style>body{font-family:system-ui,sans-serif;margin:0;padding:48px 24px;color:#1a1a1a}' +
			'h1{font-size:22px;margin:0 0 12px}p{color:#555;line-height:1.6}</style>' +
			'</head><body><h1>You are offline</h1>' +
			'<p>Koperasi Kita could not be reached. Please check your connection and try again.</p>' +
			'</body></html>',
		{ status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
	);
}

self.addEventListener('install', (event) => {
	self.skipWaiting();
	// The registration scope is the site root for this worker — the
	// domain root on a normal install, the subdirectory on a subdir
	// install — so it is the correct home page to precache.
	const home = self.registration.scope;
	event.waitUntil(
		caches
			.open(CACHE_NAME)
			.then((cache) => cache.add(new Request(home, { credentials: 'omit' })))
			.catch(() => undefined),
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) =>
				Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
			)
			.then(() => self.clients.claim())
			.catch(() => undefined),
	);
});

self.addEventListener('fetch', (event) => {
	let url;
	try {
		url = new URL(event.request.url);
	} catch (error) {
		return;
	}

	if (isStaticAsset(event.request, url)) {
		event.respondWith(
			caches.match(event.request).then((cached) => {
				if (cached) {
					return cached;
				}
				return fetch(event.request).then((response) => {
					if (response && response.ok && response.type === 'basic') {
						const copy = response.clone();
						caches
							.open(CACHE_NAME)
							.then((cache) => cache.put(event.request, copy))
							.catch(() => undefined);
					}
					return response;
				});
			}),
		);
		return;
	}

	if (event.request.mode === 'navigate') {
		event.respondWith(
			fetch(event.request).catch(() =>
				caches
					.match(self.registration.scope)
					.then((cached) => cached || offlineResponse()),
			),
		);
	}
});
JS;
    }
}

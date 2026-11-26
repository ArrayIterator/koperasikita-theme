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
        add_action('after_switch_theme', [self::class, 'ensureDefaultIcon']);
        add_action('customize_register', [self::class, 'ensureDefaultIcon']);
    }

    /**
     * The default app icon is the Koperasi Kita logo: when no icon
     * has been picked yet, copy the shipped logo into the media
     * library and set it as the icon, so the Customizer picker shows
     * the logo by default instead of an empty slot. Runs only while
     * the setting is empty (admin: theme switch + Customizer open);
     * the manifest/icon fallbacks still use the shipped file when
     * the library copy is ever deleted.
     */
    public static function ensureDefaultIcon(): void
    {
        if (absint(get_theme_mod('kk_pwa_icon', 0)) > 0) {
            return;
        }

        $logo = get_template_directory() . '/assets/img/logo-512.png';

        if (!is_file($logo)) {
            return;
        }

        $contents = file_get_contents($logo);

        if ($contents === false) {
            return;
        }

        $bits = wp_upload_bits('koperasi-kita-logo-512.png', null, $contents);

        if (!empty($bits['error']) || empty($bits['file'])) {
            return;
        }

        $id = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'Koperasi Kita',
            'post_content' => '',
            'post_status' => 'inherit',
        ], $bits['file']);

        if (is_wp_error($id) || $id <= 0) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($id, $bits['file']);
        wp_update_attachment_metadata($id, $meta);
        set_theme_mod('kk_pwa_icon', (int) $id);
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
            /* Combined-CSS mode: the split chain is one file (kk-app),
             * so depend on it instead of the missing kk-responsive. */
            $dep = \KoperasiKita\Assets\Enqueue::isCombinedCss() ? 'kk-app' : 'kk-responsive';
            wp_enqueue_style('kk-pwa', $css, [$dep], KK_VERSION);
        }
    }

    /**
     * Manifest link (+ theme colour) in <head>. The install prompt and
     * the service worker both depend on this link existing.
     */
    public static function printManifestLink(): void
    {
        echo '<link rel="manifest" href="' . esc_url(self::manifestUrl()) . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr(self::themeColor()) . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="'
            . esc_url(self::appIconUrl()) . '">' . "\n";
    }

    /**
     * The app icon URL: the Customizer-picked image when one is set,
     * otherwise the shipped logo (assets/img/logo-192.png).
     */
    public static function appIconUrl(): string
    {
        $id = absint(get_theme_mod('kk_pwa_icon', 0));

        if ($id > 0) {
            $image = wp_get_attachment_image_src($id, 'full');

            if (is_array($image) && isset($image[0]) && is_string($image[0]) && $image[0] !== '') {
                return $image[0];
            }
        }

        return get_template_directory_uri() . '/assets/img/logo-192.png';
    }

    /**
     * The manifest icons: the Customizer-picked image (one URL, used
     * for both sizes — the browser scales it) when one is set,
     * otherwise the shipped 192 + 512 logo files.
     *
     * @return array<int, array<string, string>>
     */
    public static function manifestIcons(): array
    {
        $id = absint(get_theme_mod('kk_pwa_icon', 0));

        if ($id > 0) {
            $image = wp_get_attachment_image_src($id, 'full');

            if (is_array($image) && isset($image[0]) && is_string($image[0]) && $image[0] !== '') {
                $mime = get_post_mime_type($id);
                $icon = ['src' => $image[0]];

                if (is_string($mime) && $mime !== '') {
                    $icon['type'] = $mime;
                }

                return [
                    array_merge($icon, ['sizes' => '192x192', 'purpose' => 'any']),
                    array_merge($icon, ['sizes' => '512x512', 'purpose' => 'any']),
                ];
            }
        }

        $iconsBase = get_template_directory_uri() . '/assets/img/';

        return [
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
        ];
    }

    /**
     * Manifest fields, Customizer-controlled with the shipped values
     * as fallbacks. An empty or invalid stored value falls back too,
     * so the manifest is never served with a blank name or colour.
     */
    public static function manifestName(): string
    {
        $name = sanitize_text_field((string) get_theme_mod('kk_pwa_name', 'Koperasi Kita'));

        return $name !== '' ? $name : 'Koperasi Kita';
    }

    public static function manifestShortName(): string
    {
        $name = sanitize_text_field((string) get_theme_mod('kk_pwa_short_name', 'Koperasi Kita'));

        return $name !== '' ? $name : 'Koperasi Kita';
    }

    public static function manifestDescription(): string
    {
        $description = sanitize_text_field((string) get_theme_mod(
            'kk_pwa_description',
            'News, announcements and market updates from Koperasi Kita.'
        ));

        return $description !== '' ? $description : 'News, announcements and market updates from Koperasi Kita.';
    }

    public static function themeColor(): string
    {
        $color = sanitize_hex_color((string) get_theme_mod('kk_pwa_theme_color', self::THEME_COLOR));

        return $color === null || $color === '' ? self::THEME_COLOR : $color;
    }

    public static function backgroundColor(): string
    {
        $color = sanitize_hex_color((string) get_theme_mod('kk_pwa_background_color', self::BACKGROUND_COLOR));

        return $color === null || $color === '' ? self::BACKGROUND_COLOR : $color;
    }

    /**
     * Text of the install button. Empty falls back to the
     * shipped label, so the button is never rendered blank.
     */
    public static function buttonText(): string
    {
        $text = sanitize_text_field((string) get_theme_mod('kk_pwa_button_text', __('Install app', 'koperasikita')));

        return $text !== '' ? $text : __('Install app', 'koperasikita');
    }

    /**
     * The install button style: where and how it shows. Defaults to
     * the floating button at the bottom right.
     */
    public static function buttonStyle(): string
    {
        $style = (string) get_theme_mod('kk_pwa_button_style', 'float-bottom-right');

        return in_array($style, ['float-bottom', 'float-bottom-right', 'float-bottom-left', 'float-bottom-center', 'beside-weather', 'beside-clock', 'above-header'], true) ? $style : 'float-bottom-right';
    }

    /**
     * Whether the install button shows in this position: PWA on, the
     * button enabled, not dismissed in the last hour, and the chosen
     * style matches. The sticky header is never a position.
     */
    public static function isButtonPosition(string $position): bool
    {
        if (!self::isEnabled() || !(bool) get_theme_mod('kk_pwa_button_enabled', true)) {
            return false;
        }

        if (self::isButtonDismissed()) {
            return false;
        }

        return self::buttonStyle() === $position;
    }

    /**
     * The button was dismissed (X on the banner/float) within the
     * last hour: keep it hidden until the hour passes.
     */
    public static function isButtonDismissed(): bool
    {
        $raw = $_COOKIE['koperasikita_pwa_button_dismissed'] ?? '';
        if ($raw === '' || $raw === null) {
            return false;
        }
        if (!is_numeric($raw)) {
            return true;
        }
        $ts = (int) $raw;
        $ts = $ts > 10000000000 ? (int) ($ts / 1000) : $ts;

        return $ts > 0 && (time() - $ts) < 3600;
    }

    /**
     * Description for the banner and the full bottom bar, with
     * {app_name} replaced by the app name.
     */
    public static function buttonDescription(): string
    {
        $desc = (string) get_theme_mod('kk_pwa_button_desc', 'Install {app_name} on your device for a faster and smoother experience.');
        if (trim($desc) === '') {
            $desc = 'Install {app_name} on your device for a faster and smoother experience.';
        }

        return str_replace('{app_name}', self::manifestName(), $desc);
    }

    /**
     * Delay before the install prompt appears, in milliseconds.
     * Stored as seconds (0–60) in the Customizer; 1.5s is the
     * shipped behaviour. Clamped again here so a hand-edited
     * option can never delay the prompt by minutes.
     */
    public static function promptDelayMs(): int
    {
        $seconds = (float) get_theme_mod('kk_pwa_prompt_delay', 1.5);

        return (int) round(min(60.0, max(0.0, $seconds)) * 1000);
    }

    /**
     * The install prompt modal is a template part, rendered once in the
     * footer. main.ts owns showing it when beforeinstallprompt fires;
     * this only makes sure the markup exists on the page.
     */
    public static function printInstallPrompt(): void
    {
        locate_template('template-parts/pwa/install-prompt.php', true, false);

        // Floating styles render here, in the footer: a full bottom
        // bar or a floating button at the bottom corners/center.
        $style = self::buttonStyle();
        if (str_starts_with($style, 'float-') && self::isButtonPosition($style)) {
            get_template_part('template-parts/pwa/install-button', null, ['style' => $style]);
        }
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

    /**
     * Screenshots for the manifest, from the Customizer multi-image
     * pickers. Each slot stores a list of attachment IDs (a JSON
     * array from the MultiImageControl, or a single legacy ID from
     * before the multi-image picker); every usable image becomes one
     * screenshot entry for its form factor, so the install prompt
     * can show more than one screenshot per slot. An empty slot, a
     * deleted attachment, an image without dimensions or a
     * non-image attachment is skipped, and with no usable
     * screenshot the manifest has no screenshots field at all.
     *
     * @return array<int, array<string, string>>
     */
    public static function manifestScreenshots(): array
    {
        $screenshots = [];

        foreach (['wide' => 'kk_pwa_screenshot_wide', 'narrow' => 'kk_pwa_screenshot_narrow'] as $formFactor => $setting) {
            $raw = get_theme_mod($setting, []);

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $ids = is_array($decoded) ? $decoded : [$raw];
            } elseif (is_array($raw)) {
                $ids = $raw;
            } elseif (is_int($raw) || is_numeric($raw)) {
                $ids = [(int) $raw];
            } else {
                $ids = [];
            }

            $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids), static fn (int $id): bool => $id > 0)));

            foreach ($ids as $id) {
                $image = wp_get_attachment_image_src($id, 'full');

                if ($image === false) {
                    continue;
                }

                [$url, $width, $height] = $image;

                if (!is_string($url) || $url === '' || $width <= 0 || $height <= 0) {
                    continue;
                }

                $mime = get_post_mime_type($id);

                if (is_string($mime) && $mime !== '' && !str_starts_with($mime, 'image/')) {
                    continue;
                }

                $entry = [
                    'src' => $url,
                    'sizes' => $width . 'x' . $height,
                ];

                if (is_string($mime) && $mime !== '') {
                    $entry['type'] = $mime;
                }

                $entry['form_factor'] = $formFactor;

                $screenshots[] = $entry;
            }
        }

        return $screenshots;
    }

    /**
     * The manifest document. Screenshots are added only when at
     * least one is configured, so the field is honestly absent
     * instead of an empty array.
     *
     * @return array<string, mixed>
     */
    public static function manifestData(): array
    {
        $manifest = [
            'id' => home_url('/'),
            'name' => self::manifestName(),
            'short_name' => self::manifestShortName(),
            'description' => self::manifestDescription(),
            'start_url' => home_url('/'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => self::backgroundColor(),
            'theme_color' => self::themeColor(),
            'icons' => self::manifestIcons(),
        ];

        $screenshots = self::manifestScreenshots();

        if ($screenshots !== []) {
            $manifest['screenshots'] = $screenshots;
        }

        return $manifest;
    }

    private static function serveManifest(): void
    {
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        echo wp_json_encode(self::manifestData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

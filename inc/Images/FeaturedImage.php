<?php

declare(strict_types=1);

namespace KoperasiKita\Images;

use KoperasiKita\Assets\ViteLoader;
use KoperasiKita\Customizer\Customizer;

final class FeaturedImage
{
    public static function register(): void
    {
        add_filter('wp_get_attachment_image_attributes', [self::class, 'filterImageAttributes'], 10, 3);
        add_filter('wp_lazy_loading_enabled', [self::class, 'filterLazyLoadingEnabled']);
        add_filter('jpeg_quality', [self::class, 'filterQuality']);
        add_filter('wp_editor_set_quality', [self::class, 'filterQuality']);
        add_filter('wp_calculate_image_srcset', [self::class, 'filterSrcset']);
        add_filter('wp_get_attachment_image_src', [self::class, 'filterImageSrc'], 10, 4);
        add_filter('the_content', [self::class, 'filterContent']);
        add_filter('wp_resource_hints', [self::class, 'filterResourceHints'], 10, 2);
    }

    /**
     * Whether uploaded images may be served through the CDN. The
     * toggle is opt-in, and the site must live on a real public
     * domain: rewriting on localhost, an IP or a .test/.local host
     * would point the browser at a CDN that cannot fetch the file.
     */
    public static function isCdnEnabled(): bool
    {
        if (!(bool) get_theme_mod('kk_image_cdn', false)) {
            return false;
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        if (!str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return preg_match('/\.(local|test|localhost|invalid|example)$/', $host) !== 1;
    }

    /**
     * Pass an image URL through the TrayDigita Stack's JetpackPhoton
     * provider. The provider decides eligibility itself: only
     * same-host raster images (jpg, jpeg, png, gif, webp, avif,
     * heic) are rewritten to the CDN; SVGs, external URLs and URLs
     * already on the CDN pass through unchanged. When the CDN toggle
     * is off, the site is not on a public domain, or the Stack is
     * unavailable, the original URL is returned unchanged. The theme
     * never builds a CDN URL itself.
     */
    private static function photon(string $url): string
    {
        if ($url === '' || !self::isCdnEnabled()) {
            return $url;
        }

        if (!class_exists('TrayDigita\\WP\\Stack\\Source\\Stack')) {
            return $url;
        }

        try {
            $photon = \TrayDigita\WP\Stack\Source\Stack::getInstance()->getJetpackPhoton();

            return $photon->replace($url);
        } catch (\Throwable) {
            return $url;
        }
    }

    /**
     * Rewrite the URLs in a srcset attribute value through Photon.
     * A srcset is a comma-separated list of "URL [descriptor]"
     * entries; only the URL part of each entry is rewritten.
     */
    private static function photonSrcset(string $srcset): string
    {
        $entries = explode(',', $srcset);

        foreach ($entries as &$entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $entry, 2);

            if (!is_array($parts) || $parts === [] || $parts[0] === '') {
                continue;
            }

            $parts[0] = self::photon($parts[0]);
            $entry = implode(' ', $parts);
        }

        unset($entry);

        return implode(', ', $entries);
    }

    /**
     * The CDN host for the resource hints, taken from the Stack's
     * JetpackPhoton provider, never hardcoded here. Empty when the
     * Stack is unavailable.
     */
    private static function cdnHost(): string
    {
        if (!class_exists('TrayDigita\\WP\\Stack\\Source\\Stack')) {
            return '';
        }

        try {
            $photon = \TrayDigita\WP\Stack\Source\Stack::getInstance()->getJetpackPhoton();
            $cdnUrl = $photon->urlIndex(0);
        } catch (\Throwable) {
            return '';
        }

        if (!is_string($cdnUrl) || $cdnUrl === '') {
            return '';
        }

        return (string) wp_parse_url($cdnUrl, PHP_URL_HOST);
    }

    /**
     * @param array<int, mixed>|false $image
     * @return array<int, mixed>|false
     */
    public static function filterImageSrc(array|false $image, int $attachmentId, mixed $size, bool $icon): array|false
    {
        if ($image === false || is_admin()) {
            return $image;
        }

        if (isset($image[0]) && is_string($image[0])) {
            $image[0] = self::photon($image[0]);
        }

        return $image;
    }

    public static function filterContent(string $content): string
    {
        if (is_admin() || is_feed()) {
            return $content;
        }

        if (!self::isCdnEnabled()) {
            return $content;
        }

        // Rewrite the src and srcset URLs in the markup through the
        // Stack's Photon provider. The provider only rewrites
        // eligible same-host raster images, so SVGs, external URLs
        // and non-image sources pass through unchanged. The lookbehind
        // keeps data-src and other *-src attributes out of the match.
        return (string) preg_replace_callback(
            '/(?<![\w-])(srcset|src)\s*=\s*([\'"])(.*?)\2/i',
            static function (array $matches): string {
                $isSrcset = strtolower($matches[1]) === 'srcset';
                $quote = $matches[2];
                $value = $matches[3];

                if ($isSrcset) {
                    $value = self::photonSrcset($value);
                } else {
                    $value = self::photon($value);
                }

                return $matches[1] . '=' . $quote . $value . $quote;
            },
            $content
        );
    }

    /**
     * @param array<int, mixed> $urls
     * @return array<int, mixed>
     */
    public static function filterResourceHints(array $urls, string $relationType): array
    {
        if (!self::isCdnEnabled()) {
            return $urls;
        }

        $host = self::cdnHost();

        if ($host === '') {
            return $urls;
        }

        if ($relationType === 'dns-prefetch') {
            $urls[] = '//' . $host;
        }

        if ($relationType === 'preconnect') {
            $urls[] = ['href' => 'https://' . $host, 'crossorigin'];
        }

        return $urls;
    }

    /**
     * Whether lazy-loading is enabled for images. The toggle
     * defaults to on; when it is off, no `loading="lazy"` attribute
     * is added anywhere: attachment images lose it in
     * filterImageAttributes(), and content images, avatars and
     * iframes lose it through filterLazyLoadingEnabled(), the gate
     * WordPress core asks before adding the attribute itself.
     */
    public static function isLazyEnabled(): bool
    {
        return (bool) get_theme_mod('kk_images_lazy', true);
    }

    /**
     * @param array<string, string> $attr
     * @return array<string, string>
     */
    public static function filterImageAttributes(array $attr, \WP_Post $attachment, mixed $size): array
    {
        if (self::isLazyEnabled()) {
            $attr['loading'] = 'lazy';
        } else {
            // WordPress core merges its own `loading="lazy"` into
            // $attr before this filter runs, so removing the key
            // here genuinely strips it from the attachment image.
            unset($attr['loading']);
        }

        return $attr;
    }

    /**
     * Gate for WordPress core's own lazy-loading. Core asks this
     * filter before it adds `loading="lazy"` to images inside post
     * content, avatars and iframes (wp_filter_content_tags and
     * friends). Returning false here is what makes the toggle
     * cover those paths too - filterImageAttributes() alone only
     * reaches attachment images.
     */
    public static function filterLazyLoadingEnabled(bool $enabled): bool
    {
        return self::isLazyEnabled() ? $enabled : false;
    }

    public static function filterQuality(mixed $quality): int
    {
        return Customizer::clampInt(get_theme_mod('kk_images_quality', 82), 60, 100);
    }

    /** @param array<string, array<string, mixed>>|false $sources */
    public static function filterSrcset(array|false $sources): array|false
    {
        if (!(bool) get_theme_mod('kk_images_srcset', true)) {
            return false;
        }

        if ($sources === [] || $sources === false || is_admin()) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            if (isset($source['url']) && is_string($source['url'])) {
                $sources[$width]['url'] = self::photon($source['url']);
            }
        }

        return $sources;
    }

    public static function url(int $postId, string $size = 'kk-card'): string
    {
        $url = get_the_post_thumbnail_url($postId, $size);

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return self::defaultThumbnailUrl();
    }

    /**
     * The admin-picked default thumbnail (Customizer -> Images &
     * Performance -> Default Thumbnail), or the shipped placeholder
     * when none is picked. Used for posts without a featured image
     * and as the SEO/OG image fallback.
     */
    public static function defaultThumbnailUrl(): string
    {
        $attachmentId = (int) get_theme_mod('kk_default_thumbnail', 0);

        if ($attachmentId > 0) {
            $url = wp_get_attachment_image_url($attachmentId, 'full');

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        /* The shipped placeholder. This SVG is under Vite's 4 KB inline
         * limit, so the main.ts import is inlined as a data URI and
         * dropped - Vite never emits it as a file, so no manifest entry
         * can exist for it. Resolving it through ViteLoader would
         * return the dev-server URL under WP_DEBUG instead of a real
         * file, so the plain theme file is served directly. It is the
         * same artwork; the v1 -> v2 filename change itself busts any
         * cache of the old placeholder. */
        return KK_URI . '/assets/img/placeholder-v2.svg';
    }

    public static function srcset(int $postId, string $size = 'kk-card'): string
    {
        $attachmentId = get_post_thumbnail_id($postId);

        if ($attachmentId === 0) {
            return '';
        }

        $srcset = wp_get_attachment_image_srcset($attachmentId, $size);

        return is_string($srcset) ? $srcset : '';
    }
}

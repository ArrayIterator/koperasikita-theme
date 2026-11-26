<?php

declare(strict_types=1);

namespace KoperasiKita\Images;

use KoperasiKita\Customizer\Customizer;

final class FeaturedImage
{
    private const CDN_HOST = 'i0.wp.com';

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
     * Rewrite uploaded image URLs in a URL or HTML fragment to the
     * CDN host. Only the uploads base URL is touched, so theme assets
     * and external images pass through unchanged — and a URL that is
     * already on the CDN does not match the pattern, so it is never
     * rewritten twice.
     */
    public static function rewrite(string $subject): string
    {
        if (!self::isCdnEnabled()) {
            return $subject;
        }

        $uploads = wp_get_upload_dir();
        $base = (string) preg_replace('#^https?://#', '', (string) ($uploads['baseurl'] ?? ''));

        if ($base === '') {
            return $subject;
        }

        $pattern = '#https?://' . preg_quote($base, '#') . '#';

        return (string) preg_replace($pattern, 'https://' . self::CDN_HOST . '/' . $base, $subject);
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
            $image[0] = self::rewrite($image[0]);
        }

        return $image;
    }

    public static function filterContent(string $content): string
    {
        if (is_admin() || is_feed()) {
            return $content;
        }

        return self::rewrite($content);
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

        if ($relationType === 'dns-prefetch') {
            $urls[] = '//' . self::CDN_HOST;
        }

        if ($relationType === 'preconnect') {
            $urls[] = ['href' => 'https://' . self::CDN_HOST, 'crossorigin'];
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
     * cover those paths too — filterImageAttributes() alone only
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
                $sources[$width]['url'] = self::rewrite($source['url']);
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

        /* Cache-bust the shipped placeholder: the server sends it with
         * a 10-year max-age, so a browser that cached the old artwork
         * would keep showing it for years after a redesign (the user
         * saw exactly this — "teteep" — after the #97 redesign). The
         * file's mtime as the query version makes every future edit
         * a new URL automatically, so browsers always fetch the
         * current artwork. */
        $path = get_template_directory() . '/assets/img/placeholder.svg';
        $version = is_readable( $path ) ? (string) filemtime( $path ) : KK_VERSION;

        return KK_URI . '/assets/img/placeholder.svg?v=' . $version;
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

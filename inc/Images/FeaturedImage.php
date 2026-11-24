<?php

declare(strict_types=1);

namespace KoperasiKita\Images;

use KoperasiKita\Customizer\Customizer;

final class FeaturedImage
{
    public static function register(): void
    {
        add_filter('wp_get_attachment_image_attributes', [self::class, 'filterImageAttributes'], 10, 3);
        add_filter('jpeg_quality', [self::class, 'filterQuality']);
        add_filter('wp_editor_set_quality', [self::class, 'filterQuality']);
        add_filter('wp_calculate_image_srcset', [self::class, 'filterSrcset']);
    }

    /**
     * @param array<string, string> $attr
     * @return array<string, string>
     */
    public static function filterImageAttributes(array $attr, \WP_Post $attachment, mixed $size): array
    {
        if ((bool) get_theme_mod('kk_images_lazy', true)) {
            $attr['loading'] = 'lazy';
        }

        return $attr;
    }

    public static function filterQuality(mixed $quality): int
    {
        return Customizer::clampInt(get_theme_mod('kk_images_quality', 82), 60, 100);
    }

    /** @param array<string, array<string, mixed>>|false $sources */
    public static function filterSrcset(array|false $sources): array|false
    {
        if ((bool) get_theme_mod('kk_images_srcset', true)) {
            return $sources;
        }

        return false;
    }

    public static function url(int $postId, string $size = 'kk-card'): string
    {
        $url = get_the_post_thumbnail_url($postId, $size);

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return KK_URI . '/assets/img/placeholder.svg';
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

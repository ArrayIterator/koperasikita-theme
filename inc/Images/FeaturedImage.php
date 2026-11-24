<?php

declare(strict_types=1);

namespace KoperasiKita\Images;

final class FeaturedImage
{
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

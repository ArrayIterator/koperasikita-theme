<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

final class Comments
{
    public static function register(): void
    {
        add_filter('comments_open', [self::class, 'filterCommentsOpen'], 20, 2);
        add_filter('pings_open', [self::class, 'filterPingsOpen'], 20, 2);
    }

    public static function filterCommentsOpen(bool $open, int $postId): bool
    {
        if (!self::commentsAllowed()) {
            return false;
        }

        return $open;
    }

    public static function filterPingsOpen(bool $open, int $postId): bool
    {
        if (!self::commentsAllowed()) {
            return false;
        }

        return $open;
    }

    private static function commentsAllowed(): bool
    {
        if (get_theme_mod('kk_comments_mode', 'follow') !== 'follow') {
            return false;
        }

        // Backward compat: the old kk_comments_enabled checkbox.
        return (bool) get_theme_mod('kk_comments_enabled', true);
    }

    /**
     * Whether the comments block renders for this post, honouring the
     * Hide Comments rule: `all` never renders; `disabled` renders
     * only while comments are enabled at all; `post` (default)
     * renders when this post/page allows comments or already has
     * comments to show.
     */
    public static function shouldShow(int $postId): bool
    {
        $hide = (string) get_theme_mod('kk_comments_hide', 'post');

        if ($hide === 'all') {
            return false;
        }

        if ($hide === 'disabled') {
            return self::commentsAllowed();
        }

        return comments_open($postId) || get_comments_number($postId) > 0;
    }
}

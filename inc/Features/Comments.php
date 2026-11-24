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
        return (bool) get_theme_mod('kk_comments_enabled', true);
    }
}

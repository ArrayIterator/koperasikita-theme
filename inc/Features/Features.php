<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Images\FeaturedImage;

final class Features
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return ['weather', 'markets', 'bookmarks', 'pwa', 'agenda', 'ticker'];
    }

    public static function isEnabled(string $feature): bool
    {
        if (!in_array($feature, self::all(), true)) {
            return false;
        }

        return (bool) get_theme_mod('kk_' . $feature . '_enabled', true);
    }

    public static function register(): void
    {
        Weather::register();
        Markets::register();
        Bookmarks::register();
        Agenda::register();
        Ticker::register();
        Comments::register();
        Performance::register();
        FeaturedImage::register();
    }
}

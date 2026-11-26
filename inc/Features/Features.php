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

        // Markets is always on: there is no "Enable Markets" toggle.
        // The widgets, the market strip and the gold prices are part
        // of the theme, not an optional feature.
        if ($feature === 'markets') {
            return true;
        }

        return (bool) get_theme_mod('kk_' . $feature . '_enabled', true);
    }

    public static function register(): void
    {
        Weather::register();
        Markets::register();
        DataDesk::register();
        Bookmarks::register();
        Agenda::register();
        Subscribe::register();
        Ticker::register();
        Comments::register();
        Performance::register();
        FeaturedImage::register();
        ArticleFontSize::register();
    }
}

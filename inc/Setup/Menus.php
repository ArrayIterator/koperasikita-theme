<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class Menus
{
    public const MAX_COUNT_DEFAULT = 5;
    public const MAX_COUNT_MIN = 1;
    public const MAX_COUNT_MAX = 10;

    public static function register(): void
    {
        add_filter('wp_nav_menu_objects', [self::class, 'limitBottomNavItems'], 10, 2);
        add_filter('wp_nav_menu_items', [self::class, 'appendBookmarksLink'], 10, 2);
    }

    /**
     * The bookmarks page has no menu of its own, so the footer nav
     * links to it: appended as a plain menu item (same markup and
     * classes as the items the user adds), so it looks like part of
     * the menu. Nothing is appended when no page uses the Bookmarks
     * template yet, or to any other menu location.
     */
    public static function appendBookmarksLink(string $items, object $args): string
    {
        if (($args->theme_location ?? '') !== 'footer') {
            return $items;
        }

        $page = \KoperasiKita\Features\Bookmarks::page();

        if ($page === null) {
            return $items;
        }

        $items .= sprintf(
            '<li class="menu-item"><a href="%s">%s</a></li>',
            esc_url((string) get_permalink($page->ID)),
            esc_html((string) get_the_title($page->ID))
        );

        return $items;
    }

    /**
     * Whether the mobile bottom nav renders at all
     * (Customizer -> Mobile Menu -> Display Mobile Menu).
     */
    public static function isDisplayed(): bool
    {
        return (bool) get_theme_mod('kk_mobile_menu_display', true);
    }

    /**
     * Whether the item titles show under the icons
     * (Customizer -> Mobile Menu -> Display Menu Title).
     */
    public static function showTitles(): bool
    {
        return (bool) get_theme_mod('kk_mobile_menu_show_titles', true);
    }

    /**
     * How many items the bottom bar shows at most
     * (Customizer -> Mobile Menu -> Maximum Menu Count, 1-10).
     */
    public static function maxCount(): int
    {
        $value = (int) get_theme_mod('kk_mobile_menu_max_count', self::MAX_COUNT_DEFAULT);

        return max(self::MAX_COUNT_MIN, min(self::MAX_COUNT_MAX, $value));
    }

    /**
     * Items for the mobile bottom navigation. Falls back to the primary menu
     * when no menu is assigned to the mobile-bottom location, so the bottom
     * bar is never empty on a fresh install. Capped at maxCount(): the
     * footer renders the same cap through limitBottomNavItems(), so the
     * emptiness check here and the rendered bar always agree.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public static function mobileBottomItems(): array
    {
        $locations = get_nav_menu_locations();
        $location = 'mobile-bottom';

        if (empty($locations[$location]) && !empty($locations['primary'])) {
            $location = 'primary';
        }

        if (empty($locations[$location])) {
            return [];
        }

        $items = wp_get_nav_menu_items((int) $locations[$location]);

        if (!is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            $result[] = [
                'title' => (string) $item->title,
                'url' => (string) $item->url,
            ];
        }

        return array_slice($result, 0, self::maxCount());
    }

    /**
     * Caps the rendered bottom bar at maxCount(). Gated on the
     * kk_limit_bottom_nav marker the footer passes to wp_nav_menu(),
     * so the primary nav, footer nav and sidebar menu are untouched.
     *
     * @param array<int, \WP_Post> $items
     * @return array<int, \WP_Post>
     */
    public static function limitBottomNavItems(array $items, object $args): array
    {
        if (empty($args->kk_limit_bottom_nav)) {
            return $items;
        }

        return array_slice($items, 0, self::maxCount());
    }
}

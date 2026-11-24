<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class Menus
{
    /**
     * Items for the mobile bottom navigation. Falls back to the primary menu
     * when no menu is assigned to the mobile-bottom location, so the bottom
     * bar is never empty on a fresh install.
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

        return $result;
    }
}

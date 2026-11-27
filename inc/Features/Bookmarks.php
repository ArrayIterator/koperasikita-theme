<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

final class Bookmarks
{
    public static function register(): void
    {
        // Phase 3: IndexedDB-only bookmarks (no database, user meta or REST
        // sync). Entry: postId, title, url, excerpt, thumbnail, note, createdAt.
    }

    /**
     * The page using the Bookmarks template, if one exists. The
     * template is the identity - the slug is the user's choice -
     * so menus and setup checks look the page up this way instead
     * of hardcoding a slug.
     */
    public static function page(): ?\WP_Post
    {
        $pages = get_pages([
            'meta_key' => '_wp_page_template',
            'meta_value' => 'template-bookmarks.php',
            'number' => 1,
        ]);

        if (!empty($pages) && isset($pages[0]) && $pages[0] instanceof \WP_Post) {
            return $pages[0];
        }

        return null;
    }

    /**
     * The bookmarks page URL, or '' when no page uses the template.
     */
    public static function pageUrl(): string
    {
        $page = self::page();

        return $page !== null ? (string) get_permalink($page->ID) : '';
    }
}

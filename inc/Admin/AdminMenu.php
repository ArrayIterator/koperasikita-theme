<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Agenda;
use KoperasiKita\Setup\Setup;

/**
 * The single "Koperasi Kita" top-level admin menu. All theme admin
 * pages live under it, so the editor never hunts through Appearance
 * and Settings for them:
 *
 *   Koperasi Kita
 *   ├─ Setup            (the Stack installer / status page)
 *   ├─ Sidebar Settings (sticky sidebars)
 *   ├─ Gold Prices      (Logam Mulia sync status + history)
 *   ├─ Data Desk Templates (panel + table templates)
 *   ├─ Locations        (Agenda locations)
 *   └─ Subscribers      (newsletter subscriber list)
 *
 * The pages keep their own slugs, so existing bookmarks keep
 * working. Note the hook-suffix trap, documented on
 * submenuHookSuffix() below: submenu hooks are built from the
 * sanitized menu TITLE, not from MENU_SLUG.
 */
final class AdminMenu
{
    public const MENU_SLUG = 'koperasikita';

    /**
     * Hook suffix of a submenu page under this menu.
     *
     * WordPress builds a submenu page's hook suffix from the
     * SANITIZED MENU TITLE, not from the menu slug: add_menu_page()
     * stores $admin_page_hooks[$slug] = sanitize_title($title),
     * and get_plugin_page_hookname() returns
     * "$title-slug_page_$page". The title 'Koperasi Kita'
     * sanitizes to 'koperasi-kita', so the pages' hooks are
     * 'koperasi-kita_page_<slug>' — NOT 'koperasikita_page_<slug>'.
     * Deriving it from the same translated title string used in
     * registerMenu() keeps the two in sync in every locale.
     * The wrong prefix was used in Enqueue for Gold Prices, Data
     * Desk Templates and Locations, so admin.js / agenda.js were
     * never enqueued there at all: the Gold "Sync now" button had
     * no handler and did nothing on click, with no error anywhere.
     */
    public static function submenuHookSuffix(string $pageSlug): string
    {
        return sanitize_title(__('Koperasi Kita', 'koperasikita')) . '_page_' . $pageSlug;
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu'], 9);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            esc_html__('Koperasi Kita', 'koperasikita'),
            esc_html__('Koperasi Kita', 'koperasikita'),
            'manage_options',
            self::MENU_SLUG,
            [Setup::class, 'render'],
            'dashicons-store',
            30
        );

        // First submenu replaces WP's auto-duplicate of the
        // top-level entry, labelled "Setup" so the list reads
        // as pages, not as the menu name twice.
        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Setup', 'koperasikita'),
            esc_html__('Setup', 'koperasikita'),
            'manage_options',
            self::MENU_SLUG,
            [Setup::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Sidebar Settings', 'koperasikita'),
            esc_html__('Sidebar Settings', 'koperasikita'),
            'manage_options',
            SidebarSettings::PAGE_SLUG,
            [SidebarSettings::class, 'renderSettingsPage']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Gold Prices', 'koperasikita'),
            esc_html__('Gold Prices', 'koperasikita'),
            'manage_options',
            GoldPricesPage::PAGE_SLUG,
            [GoldPricesPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Data Desk Templates', 'koperasikita'),
            esc_html__('Data Desk Templates', 'koperasikita'),
            'manage_options',
            DataDeskTemplatesPage::PAGE_SLUG,
            [DataDeskTemplatesPage::class, 'render']
        );

        /* The templates page's save / delete / duplicate handler
         * runs on the page's load hook, before any output: its
         * redirect cannot send headers once the admin chrome has
         * been output, and handling it in render() blanked the
         * page after every action. */
        add_action(
            'load-' . self::submenuHookSuffix(DataDeskTemplatesPage::PAGE_SLUG),
            [DataDeskTemplatesPage::class, 'handleActionsOnLoad']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Locations', 'koperasikita'),
            esc_html__('Locations', 'koperasikita'),
            'manage_options',
            Agenda::LOCATIONS_PAGE_SLUG,
            [AgendaLocationsSettings::class, 'renderSettingsPage']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Subscribers', 'koperasikita'),
            esc_html__('Subscribers', 'koperasikita'),
            'manage_options',
            SubscribersPage::PAGE_SLUG,
            [SubscribersPage::class, 'render']
        );
    }
}

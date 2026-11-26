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
 *   └─ Locations        (Agenda locations)
 *
 * The pages keep their own slugs, so existing bookmarks and the
 * Enqueue hook-suffix checks keep working — only the parent
 * (and therefore the hook suffix prefix) changed.
 */
final class AdminMenu
{
    public const MENU_SLUG = 'koperasikita';

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

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Locations', 'koperasikita'),
            esc_html__('Locations', 'koperasikita'),
            'manage_options',
            Agenda::LOCATIONS_PAGE_SLUG,
            [AgendaLocationsSettings::class, 'renderSettingsPage']
        );
    }
}

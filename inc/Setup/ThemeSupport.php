<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class ThemeSupport
{
    public static function register(): void
    {
        // Load the theme's own translations. WordPress only auto-loads
        // translations for themes hosted on wordpress.org; this theme is
        // distributed as a zip, so the .mo in languages/ needs this call -
        // without it even a complete id_ID.mo would never be used.
        load_theme_textdomain('koperasikita', get_template_directory() . '/languages');

        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
        add_theme_support('automatic-feed-links');
        add_theme_support('selective-refresh-widgets');
        add_theme_support('responsive-embeds');

        add_theme_support('custom-logo', [
            'flex-width' => true,
            'flex-height' => true,
        ]);

        add_theme_support('html5', [
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'script',
            'style',
        ]);

        add_image_size('kk-hero', 1200, 675, true);
        add_image_size('kk-card', 640, 360, true);
        add_image_size('kk-thumb', 320, 180, true);

        register_nav_menus([
            'primary' => esc_html__('Primary Menu', 'koperasikita'),
            'footer' => esc_html__('Footer Menu', 'koperasikita'),
            'mobile-bottom' => esc_html__('Mobile Bottom Menu', 'koperasikita'),
            'sidebar' => esc_html__('Sidebar Menu', 'koperasikita'),
        ]);
    }
}

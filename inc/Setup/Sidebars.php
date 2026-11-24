<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class Sidebars
{
    public static function register(): void
    {
        $sidebars = [
            'sidebar-1' => esc_html__('Main Sidebar', 'koperasikita'),
            'sidebar-single' => esc_html__('Article Sidebar', 'koperasikita'),
            'sidebar-page' => esc_html__('Page Sidebar', 'koperasikita'),
            'sidebar-news' => esc_html__('News Sidebar', 'koperasikita'),
        ];

        foreach ($sidebars as $id => $name) {
            register_sidebar([
                'id' => $id,
                'name' => $name,
                'before_widget' => '<section id="%1$s" class="kk-widget %2$s">',
                'after_widget' => '</section>',
                'before_title' => '<h2 class="kk-widget-title">',
                'after_title' => '</h2>',
            ]);
        }
    }
}

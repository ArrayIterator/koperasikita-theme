<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

final class Sidebars
{
    public static function register(): void
    {
        $sidebars = [
            // id => [name, description]. The description is shown in
            // Appearance -> Widgets so the editor knows where each
            // area lands on the site and how it is positioned.
            'sidebar-1' => [
                esc_html__('Main Sidebar', 'koperasikita'),
                esc_html__('Right column of the blog and archive pages, next to the post list.', 'koperasikita'),
            ],
            'sidebar-single' => [
                esc_html__('Article Sidebar', 'koperasikita'),
                esc_html__('Right column of a single article page, next to the article body.', 'koperasikita'),
            ],
            'sidebar-page' => [
                esc_html__('Page Sidebar', 'koperasikita'),
                esc_html__('Right column of a single page (About, Contact, and other pages), next to the page content.', 'koperasikita'),
            ],
            'sidebar-news' => [
                esc_html__('News Sidebar', 'koperasikita'),
                esc_html__('Right column of the homepage, below the hero and next to the Latest News list.', 'koperasikita'),
            ],
            // Hero widget areas, shown by the Hero Presentation modes
            // that pair the hero with a widget (Grid/Slider + Widget).
            'kk-hero-left' => [
                esc_html__('Hero Left Widget', 'koperasikita'),
                esc_html__('Left column of the homepage hero, next to the lead story. Only shown when the Hero Presentation pairs the hero with a left widget.', 'koperasikita'),
            ],
            'kk-hero-right' => [
                esc_html__('Hero Right Widget', 'koperasikita'),
                esc_html__('Right column of the homepage hero, next to the lead story. Only shown when the Hero Presentation pairs the hero with a right widget.', 'koperasikita'),
            ],
        ];

        foreach ($sidebars as $id => [$name, $description]) {
            register_sidebar([
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'before_widget' => '<section id="%1$s" class="kk-widget %2$s">',
                'after_widget' => '</section>',
                'before_title' => '<h2 class="kk-widget-title">',
                'after_title' => '</h2>',
            ]);
        }
    }
}

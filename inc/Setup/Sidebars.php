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
            // 404 / search-not-found sidebar. When it has widgets the
            // 404 and no-results pages are two-column (half page);
            // when empty they are full width — no fallback, no
            // hardcoded latest-news list.
            'sidebar-404' => [
                esc_html__('404 Sidebar', 'koperasikita'),
                esc_html__('Right column of the 404 page and the search page when no results are found. When empty, those pages are full width.', 'koperasikita'),
            ],
            // Agenda sidebar: right column of the single agenda item
            // and the agenda archive.
            'sidebar-agenda' => [
                esc_html__('Agenda Sidebar', 'koperasikita'),
                esc_html__('Right column of the agenda pages (single event and agenda archive), next to the event details.', 'koperasikita'),
            ],
            // Full-width content strips. These are NOT sidebars, so
            // they never get a sticky setting — they render in the
            // normal flow and disappear entirely when empty.
            'kk-below-header' => [
                esc_html__('Below Header', 'koperasikita'),
                esc_html__('Full-width strip directly below the breaking-news ticker, above the main content. Shown on every page.', 'koperasikita'),
            ],
            'kk-before-latest' => [
                esc_html__('Before Latest News', 'koperasikita'),
                esc_html__('Homepage, full-width strip directly before the Latest News section.', 'koperasikita'),
            ],
            'kk-before-highlights' => [
                esc_html__('Before Highlights', 'koperasikita'),
                esc_html__('Homepage, full-width strip directly before the Highlights (Sorotan) section.', 'koperasikita'),
            ],
            // Global block-area widget: ONE shared area for the whole
            // category-block region, locked — not one area per block.
            // When empty, the homepage sidebar drops below the
            // primary content instead of sitting beside it.
            'kk-block' => [
                esc_html__('Block Area', 'koperasikita'),
                esc_html__('Homepage, global widget in the category-block region (one shared area for all blocks, not per block). When empty, the homepage sidebar moves below the content.', 'koperasikita'),
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

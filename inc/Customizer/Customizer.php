<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Footer;
use KoperasiKita\Features\Performance;
use KoperasiKita\Features\StyleVariant;
use WP_Customize_Color_Control;
use WP_Customize_Manager;
use WP_Customize_Media_Control;

final class Customizer
{
    private const PALETTE_DEFAULTS = [
        'kk_palette_primary' => '#0B6B3A',
        'kk_palette_primary_dark' => '#07522C',
        'kk_palette_primary_light' => '#2FA36B',
        'kk_palette_gold' => '#C9962E',
        'kk_palette_gold_light' => '#F6D365',
        'kk_palette_ink' => '#1B1B1B',
        'kk_palette_muted' => '#5B6470',
        'kk_palette_paper' => '#FFFFFF',
        'kk_palette_surface' => '#F7F7F5',
        'kk_palette_border' => '#E6E3DE',
    ];

    /**
     * Typography size settings: theme-mod id => [CSS variable, default px, min px, max px].
     * Mirrors the kk_title_size / kk_card_title_size / kk_excerpt_size controls
     * in register(), the --kk-*-size defaults in tokens.css, and the ranges
     * of their sanitize callbacks.
     */
    private const SIZE_SETTINGS = [
        'kk_title_size' => ['--kk-title-size', 40, 24, 72],
        'kk_card_title_size' => ['--kk-card-title-size', 20, 14, 32],
        'kk_excerpt_size' => ['--kk-excerpt-size', 14, 12, 20],
    ];

    private const PALETTE_LABELS = [
        'kk_palette_primary' => 'Primary Color',
        'kk_palette_primary_dark' => 'Primary Dark Color',
        'kk_palette_primary_light' => 'Primary Light Color',
        'kk_palette_gold' => 'Gold Color',
        'kk_palette_gold_light' => 'Gold Light Color',
        'kk_palette_ink' => 'Ink Color',
        'kk_palette_muted' => 'Muted Color',
        'kk_palette_paper' => 'Paper Color',
        'kk_palette_surface' => 'Surface Color',
        'kk_palette_border' => 'Border Color',
    ];

    public static function register(WP_Customize_Manager $wpCustomize): void
    {
        $wpCustomize->add_panel('koperasikita_theme_options', [
            'title' => esc_html__('Koperasi Kita Theme Options', 'koperasikita'),
            'priority' => 30,
        ]);

        $sections = [
            'koperasikita_colors' => esc_html__('Color Palette', 'koperasikita'),
            'koperasikita_typography' => esc_html__('Typography', 'koperasikita'),
            'koperasikita_header' => esc_html__('Header & Breaking News', 'koperasikita'),
            'koperasikita_homepage' => esc_html__('Homepage Layout', 'koperasikita'),
            'koperasikita_single' => esc_html__('Single Post', 'koperasikita'),
            'koperasikita_comments' => esc_html__('Comments', 'koperasikita'),
            'koperasikita_accessibility' => esc_html__('Appearance & Accessibility', 'koperasikita'),
            'koperasikita_media' => esc_html__('Images & Performance', 'koperasikita'),
            'koperasikita_advanced' => esc_html__('Advanced Performance & Cleanup', 'koperasikita'),
            'koperasikita_breadcrumb' => esc_html__('Breadcrumbs', 'koperasikita'),
            'koperasikita_footer' => esc_html__('Footer', 'koperasikita'),
            'koperasikita_pwa' => esc_html__('Progressive Web App', 'koperasikita'),
        ];

        foreach ($sections as $id => $title) {
            $wpCustomize->add_section($id, [
                'title' => $title,
                'panel' => 'koperasikita_theme_options',
            ]);
        }

        foreach (self::PALETTE_DEFAULTS as $id => $default) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeColor'], 'postMessage');

            $wpCustomize->add_control(new WP_Customize_Color_Control($wpCustomize, $id, [
                'label' => esc_html__(self::PALETTE_LABELS[$id], 'koperasikita'),
                'section' => 'koperasikita_colors',
                'settings' => $id,
            ]));
        }

        self::addSetting(
            $wpCustomize,
            'kk_font_body',
            'system',
            [Fonts::class, 'sanitizeBody'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_font_body', [
            'label' => esc_html__('Body Font', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'type' => 'select',
            'choices' => Fonts::choices('body'),
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_font_headings',
            'system-serif',
            [Fonts::class, 'sanitizeHeadings'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_font_headings', [
            'label' => esc_html__('Headings Font', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'type' => 'select',
            'choices' => Fonts::choices('headings'),
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_title_size',
            40,
            static fn ($value) => self::clampInt($value, 24, 72),
            'postMessage'
        );
        $wpCustomize->add_control('kk_title_size', [
            'label' => esc_html__('Post Title Size', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 24, 'max' => 72],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_card_title_size',
            20,
            static fn ($value) => self::clampInt($value, 14, 32),
            'postMessage'
        );
        $wpCustomize->add_control('kk_card_title_size', [
            'label' => esc_html__('Card Title Size', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 14, 'max' => 32],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_excerpt_size',
            14,
            static fn ($value) => self::clampInt($value, 12, 20),
            'postMessage'
        );
        $wpCustomize->add_control('kk_excerpt_size', [
            'label' => esc_html__('Excerpt Size', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 12, 'max' => 20],
        ]);

        Fonts::registerControls($wpCustomize);

        self::addSetting(
            $wpCustomize,
            'kk_hero_count',
            5,
            static fn ($value) => self::clampInt($value, 3, 8),
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_count', [
            'label' => esc_html__('Hero Posts', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 3, 'max' => 8],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_latest_count',
            10,
            static fn ($value) => self::clampInt($value, 5, 20),
            'refresh'
        );
        $wpCustomize->add_control('kk_latest_count', [
            'label' => esc_html__('Latest Posts', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 5, 'max' => 20],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_homepage_layout',
            'grid',
            [self::class, 'sanitizeLayout'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_homepage_layout', [
            'label' => esc_html__('Homepage Layout', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'grid' => esc_html__('Grid', 'koperasikita'),
                'list' => esc_html__('List', 'koperasikita'),
                'featured' => esc_html__('Featured', 'koperasikita'),
                'newsletter' => esc_html__('Newsletter', 'koperasikita'),
            ],
        ]);

        // Comment count on news cards: off by default, so a card never
        // shows a bare "0 comments" line. Turn it on when the site has
        // real discussion to show.
        self::addSetting(
            $wpCustomize,
            'kk_card_show_comments',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_card_show_comments', [
            'label' => esc_html__('Show Comment Count on Cards', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'description' => esc_html__('Shows the "N comments" line on news cards.', 'koperasikita'),
        ]);

        // Homepage category blocks: three slots, each either a chosen
        // category or automatic (most posts), with its own on/off
        // switch, its own post count and its own display style (Grid /
        // List / Featured). Slot order is the display order on the
        // front page.
        for ($slot = 1; $slot <= 3; $slot++) {
            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_enabled',
                true,
                [self::class, 'sanitizeBool'],
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_enabled', [
                'label' => sprintf(esc_html__('Show Category Block %d', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
            ]);

            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot,
                0,
                [self::class, 'sanitizeCategoryChoice'],
                'refresh'
            );
            $wpCustomize->add_control(new TermSelectControl($wpCustomize, 'kk_homepage_category_' . $slot, [
                'label' => sprintf(esc_html__('Category Block %d', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'taxonomy' => 'category',
                'static_choices' => [0 => esc_html__('Automatic (most posts)', 'koperasikita')],
            ]));

            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_count',
                4,
                static fn ($value) => self::clampInt($value, 1, 8),
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_count', [
                'label' => sprintf(esc_html__('Category Block %d: Posts', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'number',
                'input_attrs' => ['min' => 1, 'max' => 8],
            ]);

            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_layout',
                'grid',
                [self::class, 'sanitizeLayout'],
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_layout', [
                'label' => sprintf(esc_html__('Category Block %d: Display Style', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'select',
                'choices' => [
                    'grid' => esc_html__('Grid', 'koperasikita'),
                    'list' => esc_html__('List', 'koperasikita'),
                    'featured' => esc_html__('Featured', 'koperasikita'),
                ],
            ]);
        }

        // Market strip on the homepage: gold spot, stocks and crypto above
        // the latest news. On by default so prices are visible on a fresh
        // install; with no price data yet the strip shows an honest empty
        // state until snapshots are entered in Settings -> Markets.
        self::addSetting(
            $wpCustomize,
            'kk_homepage_markets_strip',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_homepage_markets_strip', [
            'label' => esc_html__('Show Market Strip on Homepage', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'description' => esc_html__('Gold spot, stocks and crypto prices above the latest news. Prices are edited in Settings -> Markets.', 'koperasikita'),
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_archive_layout',
            'grid',
            [self::class, 'sanitizeArchiveLayout'],
            'refresh'
        );
        $wpCustomize->add_control('kk_archive_layout', [
            'label' => esc_html__('Archive and Search Article Layout', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'grid' => esc_html__('Cards', 'koperasikita'),
                'list' => esc_html__('List', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_pagination_mode',
            'pagination',
            [self::class, 'sanitizePaginationMode'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pagination_mode', [
            'label' => esc_html__('News Pagination', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'pagination' => esc_html__('Page numbers', 'koperasikita'),
                'load-more' => esc_html__('Load more', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_logo_invert_dark',
            false,
            [self::class, 'sanitizeBool'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_logo_invert_dark', [
            'label' => esc_html__('Invert Logo in Dark Mode', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'checkbox',
        ]);

        /* Excerpt: how long the card/hero excerpt runs, and the
         * ending shown when it is cut short (default the 3 dots). */
        self::addSetting(
            $wpCustomize,
            'kk_excerpt_length',
            55,
            [self::class, 'sanitizeExcerptLength'],
            'refresh'
        );
        $wpCustomize->add_control('kk_excerpt_length', [
            'label' => esc_html__('Excerpt Length (words)', 'koperasikita'),
            'description' => esc_html__('How many words the excerpt shows before it is cut short. Applies to card and hero excerpts.', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'number',
            'input_attrs' => ['min' => 10, 'max' => 200, 'step' => 1],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_excerpt_more',
            '...',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_excerpt_more', [
            'label' => esc_html__('Excerpt Ending', 'koperasikita'),
            'description' => esc_html__('The text shown when an excerpt is cut short. Leave the default three dots, or type your own ending.', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_footer_text',
            '',
            'sanitize_text_field',
            'postMessage'
        );
        $wpCustomize->add_control('kk_footer_text', [
            'label' => esc_html__('Footer Copyright Text', 'koperasikita'),
            'description' => esc_html__('Tokens: {year}, {month}, {month_number}, {day}, {date}, {site_name}, {site_url}, {home_url}.', 'koperasikita'),
            'section' => 'koperasikita_footer',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_footer_alignment',
            'left',
            [self::class, 'sanitizeAlignment'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_footer_alignment', [
            'label' => esc_html__('Copyright Alignment', 'koperasikita'),
            'section' => 'koperasikita_footer',
            'type' => 'select',
            'choices' => [
                'left' => esc_html__('Left', 'koperasikita'),
                'center' => esc_html__('Center', 'koperasikita'),
                'right' => esc_html__('Right', 'koperasikita'),
            ],
        ]);

        foreach (Footer::NETWORKS as $network => $label) {
            self::addSetting(
                $wpCustomize,
                'kk_footer_social_' . $network,
                '',
                'esc_url_raw'
            );
            $wpCustomize->add_control('kk_footer_social_' . $network, [
                'label' => sprintf(esc_html__('%s URL', 'koperasikita'), esc_html($label)),
                'section' => 'koperasikita_footer',
                'type' => 'url',
            ]);
        }

        $toggles = [
            'kk_ticker_enabled' => ['koperasikita_header', 'Enable News Ticker'],
            'kk_weather_enabled' => ['koperasikita_header', 'Enable Weather'],
            'kk_markets_enabled' => ['koperasikita_homepage', 'Enable Markets'],
            'kk_bookmarks_enabled' => ['koperasikita_accessibility', 'Enable Bookmarks'],
            'kk_pwa_enabled' => ['koperasikita_pwa', 'Enable Progressive Web App'],
            'kk_agenda_enabled' => ['koperasikita_homepage', 'Enable Agenda'],
        ];

        foreach ($toggles as $id => [$section, $label]) {
            self::addSetting($wpCustomize, $id, true, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => $section,
                'type' => 'checkbox',
            ]);
        }

        // Weather cache lifetime (client-side localStorage cache for
        // the top-bar and widget weather). 0 disables the browser
        // cache: every page load fetches fresh data.
        self::addSetting(
            $wpCustomize,
            'kk_weather_cache_lifetime',
            15,
            static fn ($value) => self::clampInt($value, 0, 1440),
            'refresh'
        );
        $wpCustomize->add_control('kk_weather_cache_lifetime', [
            'label' => esc_html__('Weather Cache Lifetime', 'koperasikita'),
            'description' => esc_html__('Minutes the browser keeps weather data in local storage before fetching fresh data. The top bar shows the cached weather instantly while fresh data loads in the background. 0 disables the browser cache.', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'number',
            'input_attrs' => [
                'min' => 0,
                'max' => 1440,
                'step' => 1,
            ],
        ]);

        $checkboxes = [
            'kk_single_featured' => ['koperasikita_single', 'Show Featured Image', true],
            'kk_single_author_box' => ['koperasikita_single', 'Show Author Box', true],
            'kk_single_related' => ['koperasikita_single', 'Show Related News', true],
            'kk_single_reading_time' => ['koperasikita_single', 'Show Reading Time', true],
            'kk_single_show_share' => ['koperasikita_single', 'Show Share Buttons', true],
            'kk_comments_enabled' => ['koperasikita_comments', 'Enable Comments', true],
            'kk_comments_avatars' => ['koperasikita_comments', 'Show Comment Avatars', true],
            'kk_images_lazy' => ['koperasikita_media', 'Lazy-Load Images', true],
            'kk_images_srcset' => ['koperasikita_media', 'Enable Responsive Images', true],
            'kk_breadcrumb_enabled' => ['koperasikita_breadcrumb', 'Show Breadcrumbs', true],
            'kk_breadcrumb_home' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on the Homepage', false],
            'kk_topbar_enabled' => ['koperasikita_header', 'Show Top Bar', true],
            'kk_topbar_date' => ['koperasikita_header', 'Show Date in Top Bar', true],
            'kk_clock_enabled' => ['koperasikita_header', 'Show Live Clock in Top Bar', true],
            'kk_clock_seconds' => ['koperasikita_header', 'Show Seconds on the Clock', false],
            'kk_clock_24h' => ['koperasikita_header', 'Use the 24-Hour Clock', true],
            'kk_sticky_header' => ['koperasikita_header', 'Sticky Header Navigation', true],
            'kk_sidebar_sticky' => ['koperasikita_accessibility', 'Sticky Sidebar While Scrolling', false],
        ];

        foreach ($checkboxes as $id => [$section, $label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => $section,
                'type' => 'checkbox',
            ]);
        }

        // Header: breaking-news title and source, top bar, live clock,
        // sticky header. The title is the label on the ticker itself.
        self::addSetting(
            $wpCustomize,
            'kk_breaking_title',
            __('Breaking', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_breaking_title', [
            'label' => esc_html__('Breaking News Title', 'koperasikita'),
            'description' => esc_html__('The label shown on the news ticker.', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'text',
        ]);

        /* Breaking ribbon style: five label designs, all high
         * contrast (gold 7.89:1, green 6.61:1, dark 18.73:1, red
         * 6.47:1, outline 18.73:1 on the light ticker). The old
         * single gold look was the complaint: "jelek banget". */
        self::addSetting(
            $wpCustomize,
            'kk_breaking_style',
            'gold',
            [self::class, 'sanitizeBreakingStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_breaking_style', [
            'label' => esc_html__('Breaking Ribbon Style', 'koperasikita'),
            'description' => esc_html__('The design of the Breaking label on the news ticker.', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => [
                'gold' => esc_html__('Gold (solid gold, black text)', 'koperasikita'),
                'green' => esc_html__('Green (brand green, white text)', 'koperasikita'),
                'dark' => esc_html__('Dark (near-black, white text)', 'koperasikita'),
                'red' => esc_html__('Red (alert red, white text)', 'koperasikita'),
                'outline' => esc_html__('Outline (gold border, dark text)', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_breaking_source',
            'latest',
            [self::class, 'sanitizeBreakingSource'],
            'refresh'
        );
        $wpCustomize->add_control('kk_breaking_source', [
            'label' => esc_html__('Breaking News Posts', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => [
                'latest' => esc_html__('Latest posts', 'koperasikita'),
                'category' => esc_html__('Selected categories', 'koperasikita'),
                'tag' => esc_html__('Selected tags', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_breaking_category_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_breaking_category_ids', [
            'label' => esc_html__('Ticker Categories', 'koperasikita'),
            'description' => esc_html__('Used when the source above is "Selected categories". Leave empty to fall back to the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_header',
            'taxonomy' => 'category',
        ]));

        self::addSetting(
            $wpCustomize,
            'kk_breaking_tag_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_breaking_tag_ids', [
            'label' => esc_html__('Ticker Tags', 'koperasikita'),
            'description' => esc_html__('Used when the source above is "Selected tags". Leave empty to fall back to the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_header',
            'taxonomy' => 'post_tag',
        ]));

        self::addSetting(
            $wpCustomize,
            'kk_breaking_speed',
            5,
            static fn ($value) => self::clampInt($value, 2, 120),
            'refresh'
        );
        $wpCustomize->add_control('kk_breaking_speed', [
            'label' => esc_html__('Seconds Between Breaking News Headlines', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'number',
            'input_attrs' => ['min' => 2, 'max' => 120],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_breaking_count',
            5,
            static fn ($value) => self::clampInt($value, 1, 10),
            'refresh'
        );
        $wpCustomize->add_control('kk_breaking_count', [
            'label' => esc_html__('Breaking News Headlines', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 10],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_topbar_date_format',
            'l, j F Y',
            [self::class, 'sanitizeDateFormat'],
            'refresh'
        );
        $wpCustomize->add_control('kk_topbar_date_format', [
            'label' => esc_html__('Top Bar Date Format', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => [
                'l, j F Y' => esc_html__('Monday, 25 November 2026', 'koperasikita'),
                'j F Y' => esc_html__('25 November 2026', 'koperasikita'),
                'D, j M Y' => esc_html__('Mon, 25 Nov 2026', 'koperasikita'),
                'j/m/Y' => esc_html__('25/11/2026', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_clock_timezone',
            'site',
            [self::class, 'sanitizeClockTimezone'],
            'refresh'
        );
        $wpCustomize->add_control('kk_clock_timezone', [
            'label' => esc_html__('Clock Time Zone', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => [
                'site' => esc_html__('Site time zone (Settings > General)', 'koperasikita'),
                'device' => esc_html__('Visitor device time', 'koperasikita'),
                'custom' => esc_html__('Custom time zone', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_clock_custom_timezone',
            'Asia/Jakarta',
            [self::class, 'sanitizeCustomTimezone'],
            'refresh'
        );
        $wpCustomize->add_control('kk_clock_custom_timezone', [
            'label' => esc_html__('Custom Time Zone', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => self::timezoneChoices(),
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_clock_separator',
            'colon',
            [self::class, 'sanitizeClockSeparator'],
            'refresh'
        );
        $wpCustomize->add_control('kk_clock_separator', [
            'label' => esc_html__('Clock Separator', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'choices' => [
                'colon' => esc_html__('Colon (14:05:30)', 'koperasikita'),
                'dot' => esc_html__('Dot (14.05.30)', 'koperasikita'),
            ],
        ]);

        // Progressive Web App: the manifest fields. The install itself
        // is wired in Features\Pwa; these only name and describe it.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_name',
            'Koperasi Kita',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_name', [
            'label' => esc_html__('App Name', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_pwa_short_name',
            'Koperasi Kita',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_short_name', [
            'label' => esc_html__('App Short Name', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_pwa_description',
            'News, announcements and market updates from Koperasi Kita.',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_description', [
            'label' => esc_html__('App Description', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
        ]);

        // App icon (the PWA logo). Pick an image from the media
        // library; it becomes the manifest icons and the
        // apple-touch-icon. Leave empty to use the shipped logo
        // (assets/img/logo-192.png / logo-512.png).
        self::addSetting($wpCustomize, 'kk_pwa_icon', 0, 'absint', 'refresh');

        $wpCustomize->add_control(new WP_Customize_Media_Control($wpCustomize, 'kk_pwa_icon', [
            'label' => esc_html__('App Icon', 'koperasikita'),
            'description' => esc_html__('The app logo used when installing the PWA (manifest icons and home-screen icon). Use a square image, at least 512×512. Leave empty to use the shipped logo.', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'settings' => 'kk_pwa_icon',
            'mime_type' => 'image',
        ]));

        $pwa_colors = [
            'kk_pwa_theme_color' => ['#0B6B3A', 'App Theme Color'],
            'kk_pwa_background_color' => ['#FFFFFF', 'App Background Color'],
        ];

        foreach ($pwa_colors as $id => [$default, $label]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeColor'], 'refresh');

            $wpCustomize->add_control(new WP_Customize_Color_Control($wpCustomize, $id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_pwa',
                'settings' => $id,
            ]));
        }

        // Text of the install button (and its accessible label).
        self::addSetting(
            $wpCustomize,
            'kk_pwa_button_text',
            __('Install app', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_button_text', [
            'label' => esc_html__('Install Button Text', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
        ]);

        // Whether the install button/banner shows at all. When off,
        // the install prompt modal is still the install path.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_button_enabled',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_button_enabled', [
            'label' => esc_html__('Show Install App Button', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'checkbox',
            'description' => esc_html__('Shows an install button/banner on the site when PWA is available.', 'koperasikita'),
        ]);

        // Where and how the install button shows: floating at the
        // bottom corners/center, a full bottom bar, inline in the top
        // bar, or sliding down as a banner above the header. The
        // sticky header is never an option.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_button_style',
            'float-bottom-right',
            [self::class, 'sanitizePwaButtonStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_button_style', [
            'label' => esc_html__('Install Button Position & Style', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'select',
            'choices' => [
                'float-bottom' => esc_html__('Bottom Float', 'koperasikita'),
                'float-bottom-right' => esc_html__('Float Bottom Right', 'koperasikita'),
                'float-bottom-left' => esc_html__('Float Bottom Left', 'koperasikita'),
                'float-bottom-center' => esc_html__('Float Bottom Center', 'koperasikita'),
                'beside-weather' => esc_html__('Beside Weather (Right)', 'koperasikita'),
                'beside-clock' => esc_html__('Beside Clock (Left)', 'koperasikita'),
                'above-header' => esc_html__('Above Top Header (Slide Animation)', 'koperasikita'),
            ],
            'description' => esc_html__('Select where and how the install app prompt button is displayed: floating at the bottom corners/center, full bottom bar, inline in the top bar header, or sliding down as a banner above the top header.', 'koperasikita'),
        ]);

        // Description shown on the banner and the full bottom bar,
        // with {app_name} replaced by the app name.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_button_desc',
            __('Install {app_name} on your device for a faster and smoother experience.', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_button_desc', [
            'label' => esc_html__('Banner & Floating Description', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
        ]);

        // Seconds to wait after the browser offers installation before
        // the install prompt appears. 0 shows it straight away.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_prompt_delay',
            1.5,
            static fn ($value) => self::clampFloat($value, 0, 60),
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_prompt_delay', [
            'label' => esc_html__('Install Prompt Delay', 'koperasikita'),
            'description' => esc_html__('Seconds to wait after the browser offers installation before showing the install prompt. 0 shows it immediately.', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'number',
            'input_attrs' => [
                'min' => 0,
                'max' => 60,
                'step' => 0.5,
            ],
        ]);

        // Install screenshots for the manifest (shown by the browser
        // on the install prompt). Each slot takes MULTIPLE images: a
        // dynamic list — add as many as needed, remove any. An empty
        // slot is skipped, and with neither set the manifest has no
        // screenshots field at all.
        $pwa_screenshots = [
            'kk_pwa_screenshot_wide' => [
                'Install Screenshot (Wide)',
                'Desktop screenshots shown when installing the app (landscape, e.g. 1280x720). Add as many as you like. Leave empty to skip.',
            ],
            'kk_pwa_screenshot_narrow' => [
                'Install Screenshot (Narrow)',
                'Phone screenshots shown when installing the app (portrait, e.g. 390x844). Add as many as you like. Leave empty to skip.',
            ],
        ];

        foreach ($pwa_screenshots as $id => [$label, $description]) {
            self::addSetting($wpCustomize, $id, [], [self::class, 'sanitizeAttachmentIds'], 'refresh');

            $wpCustomize->add_control(new MultiImageControl($wpCustomize, $id, [
                'label' => esc_html__($label, 'koperasikita'),
                'description' => esc_html__($description, 'koperasikita'),
                'section' => 'koperasikita_pwa',
                'settings' => $id,
            ]));
        }

        // Default article text size in pixels. Visitors can change it
        // with the resize control before the content; their choice is
        // stored in a cookie and overrides this default.
        self::addSetting(
            $wpCustomize,
            'kk_single_font_size',
            16,
            static fn ($value) => self::clampInt($value, 8, 24),
            'refresh'
        );
        $wpCustomize->add_control('kk_single_font_size', [
            'label' => esc_html__('Article Text Size', 'koperasikita'),
            'section' => 'koperasikita_single',
            'type' => 'number',
            'input_attrs' => ['min' => 8, 'max' => 24],
        ]);

        // Advanced Performance & Cleanup: one checkbox per Performance::options()
        // entry, registered as `kk_adv_<key>`. The pre-release
        // `kk_adv_cleanup_head` toggle is retired: its removals are covered by
        // `remove_generator` and `remove_head_links` below, both on by default.
        foreach (Performance::options() as $key => [$label, $default]) {
            $id = 'kk_adv_' . $key;
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_advanced',
                'type' => 'checkbox',
            ]);
        }

        /* Serve combined CSS: one stylesheet instead of the ~13-file
         * split chain (app -> tokens -> base -> header -> ... ->
         * responsive -> newsletter). Each split file is a render-
         * blocking request chained on the previous one, so the chain
         * delays first paint; the combined file is built once per
         * theme build (same cascade order, same conditionals) and
         * served as a single request. Off by default: the split files
         * are easier to debug and let the browser cache each file
         * independently. */
        self::addSetting(
            $wpCustomize,
            'kk_combine_css',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_combine_css', [
            'label' => esc_html__('Serve Combined CSS (one file)', 'koperasikita'),
            'description' => esc_html__('Combine all theme stylesheets into a single CSS file (one render-blocking request instead of ~13 chained ones). The file is rebuilt automatically when the theme is updated.', 'koperasikita'),
            'section' => 'koperasikita_advanced',
            'type' => 'checkbox',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_comments_order',
            'oldest',
            [self::class, 'sanitizeOrder'],
            'refresh'
        );
        $wpCustomize->add_control('kk_comments_order', [
            'label' => esc_html__('Comment Order', 'koperasikita'),
            'section' => 'koperasikita_comments',
            'type' => 'select',
            'choices' => [
                'oldest' => esc_html__('Oldest First', 'koperasikita'),
                'newest' => esc_html__('Newest First', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_image_cdn',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_image_cdn', [
            'label' => esc_html__('Serve Images Through the WordPress.com CDN (i0.wp.com)', 'koperasikita'),
            'description' => esc_html__('Rewrites uploaded image URLs to i0.wp.com and adds DNS prefetch and preconnect hints. Only applies on a publicly reachable domain — local and test hosts keep serving images directly.', 'koperasikita'),
            'section' => 'koperasikita_media',
            'type' => 'checkbox',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_images_quality',
            82,
            static fn ($value) => self::clampInt($value, 60, 100),
            'refresh'
        );
        $wpCustomize->add_control('kk_images_quality', [
            'label' => esc_html__('JPEG Quality', 'koperasikita'),
            'section' => 'koperasikita_media',
            'type' => 'number',
            'input_attrs' => ['min' => 60, 'max' => 100],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_breadcrumb_separator',
            '›',
            [self::class, 'sanitizeSeparator'],
            'refresh'
        );
        $wpCustomize->add_control('kk_breadcrumb_separator', [
            'label' => esc_html__('Breadcrumb Separator', 'koperasikita'),
            'section' => 'koperasikita_breadcrumb',
            'type' => 'select',
            'choices' => [
                '›' => esc_html__('› Angle Bracket', 'koperasikita'),
                '/' => esc_html__('/ Slash', 'koperasikita'),
                '»' => esc_html__('» Double Angle Bracket', 'koperasikita'),
                '—' => esc_html__('— Em Dash', 'koperasikita'),
            ],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_style_variant',
            'classic',
            [self::class, 'sanitizeVariant'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_style_variant', [
            'label' => esc_html__('Style Variant', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'classic' => esc_html__('Classic Editorial', 'koperasikita'),
                'compact' => esc_html__('Compact', 'koperasikita'),
                'bold' => esc_html__('Bold Headlines', 'koperasikita'),
            ],
        ]);

        // Palette overrides are hooked from Theme::boot() (wp_enqueue_scripts),
        // not here: customize_register only fires inside the Customizer, so
        // hooking here would leave the live site without the overrides.
    }

    public static function outputPaletteOverrides(): void
    {
        $overrides = [];
        foreach (self::PALETTE_DEFAULTS as $id => $default) {
            $value = (string) get_theme_mod($id, $default);

            if (strtolower($value) !== strtolower($default)) {
                $overrides[$id] = $value;
            }
        }

        if ($overrides === []) {
            return;
        }

        $map = [
            'kk_palette_primary' => '--kk-green',
            'kk_palette_primary_dark' => '--kk-green-dark',
            'kk_palette_primary_light' => '--kk-green-light',
            'kk_palette_gold' => '--kk-gold',
            'kk_palette_gold_light' => '--kk-gold-light',
            'kk_palette_ink' => '--kk-ink',
            'kk_palette_muted' => '--kk-muted',
            'kk_palette_paper' => '--kk-paper',
            'kk_palette_surface' => '--kk-surface',
            'kk_palette_border' => '--kk-border',
        ];

        $css = ':root{';

        foreach ($overrides as $id => $value) {
            $css .= $map[$id] . ':' . sanitize_hex_color($value) . ';';
        }

        $css .= '}';

        wp_add_inline_style('kk-app', $css);
    }

    /**
     * Print the saved typography sizes onto the live site. The three size
     * settings only ever ran inside the Customizer preview (the preview JS
     * sets the --kk-*-size variables as an inline style on <html>), so on
     * the live site the tokens.css defaults always won and the settings did
     * nothing. This mirrors the palette overrides: only values that differ
     * from their defaults are printed.
     *
     * The overrides are also repeated under each style-variant body class.
     * The variant rules in variants.css (body.kk-variant-compact /
     * body.kk-variant-bold) have higher specificity than :root, so a bare
     * :root rule would lose to them — while the preview's inline style on
     * <html> always beats the variants, so the live site must too. When no
     * size differs from its default nothing is printed and the variant
     * values apply untouched.
     */
    public static function outputTypographySizeOverrides(): void
    {
        $declarations = '';

        foreach (self::SIZE_SETTINGS as $id => [$variable, $default, $min, $max]) {
            $value = self::clampInt(get_theme_mod($id, $default), $min, $max);

            if ($value !== $default) {
                $declarations .= $variable . ':' . $value . 'px;';
            }
        }

        if ($declarations === '') {
            return;
        }

        $css = ':root{' . $declarations . '}';

        // 'classic' is the default look and defines no size overrides in
        // variants.css, so it needs no scoped rule.
        foreach (StyleVariant::all() as $variant) {
            if ($variant === 'classic') {
                continue;
            }

            $css .= 'body.kk-variant-' . $variant . '{' . $declarations . '}';
        }

        wp_add_inline_style('kk-app', $css);
    }

    /**
     * Add the is-inverted class to the custom logo image when the
     * "Invert Logo in Dark Mode" setting is on, so the dark-mode invert
     * rule in app.css applies on the live site (not just in the preview).
     */
    public static function filterCustomLogo(string $html): string
    {
        if (!(bool) get_theme_mod('kk_logo_invert_dark', false)) {
            return $html;
        }

        return str_replace('class="custom-logo"', 'class="custom-logo is-inverted"', $html);
    }

    public static function filterExcerptLength(int $length): int
    {
        return (int) get_theme_mod('kk_excerpt_length', 55);
    }

    public static function filterExcerptMore(string $more): string
    {
        $ending = (string) get_theme_mod('kk_excerpt_more', '...');

        return $ending !== '' ? $ending : '...';
    }

    private static function addSetting(
        WP_Customize_Manager $wpCustomize,
        string $id,
        mixed $default,
        callable $sanitize,
        string $transport = 'refresh'
    ): void {
        $wpCustomize->add_setting($id, [
            'default' => $default,
            'sanitize_callback' => $sanitize,
            'transport' => $transport,
        ]);
    }

    public static function sanitizeColor(mixed $value): string
    {
        $color = sanitize_hex_color((string) $value);

        return $color === null ? '' : $color;
    }

    public static function sanitizeCategoryChoice(mixed $value): int
    {
        $id = (int) $value;

        if ($id <= 0) {
            return 0;
        }

        $term = get_term($id, 'category');

        return $term instanceof \WP_Term ? $id : 0;
    }

    public static function sanitizeArchiveLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'list'], true) ? (string) $value : 'grid';
    }

    public static function sanitizePaginationMode(mixed $value): string
    {
        return in_array($value, ['pagination', 'load-more'], true) ? (string) $value : 'pagination';
    }

    public static function sanitizeBreakingSource(mixed $value): string
    {
        return in_array($value, ['latest', 'category', 'tag'], true) ? (string) $value : 'latest';
    }

    public static function sanitizeBreakingStyle(mixed $value): string
    {
        $allowed = ['gold', 'green', 'dark', 'red', 'outline'];

        return in_array($value, $allowed, true) ? (string) $value : 'gold';
    }

    /**
     * Normalise a term-ID list. Accepts a plain array (direct
     * set_theme_mod), a JSON array string (the Customizer checkbox
     * control) or a comma-separated string; drops anything that is not
     * a positive integer.
     *
     * @return array<int>
     */
    public static function sanitizeTermIds(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        // Cast, do not abs(): a negative raw ID is invalid input, not
        // the term with the matching positive ID.
        $ids = array_map(static fn (mixed $id): int => (int) $id, $value);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    /**
     * Attachment IDs for a multi-image setting: accepts a JSON array
     * (the MultiImageControl hidden input), a plain array, or a
     * single legacy attachment ID, and always returns a clean array
     * of unique positive IDs.
     *
     * @return array<int>
     */
    public static function sanitizeAttachmentIds(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (is_int($value) || is_numeric($value)) {
            $value = [(int) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = array_map('absint', $value);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    public static function sanitizeClockTimezone(mixed $value): string
    {
        return in_array($value, ['site', 'device', 'custom'], true) ? (string) $value : 'site';
    }

    public static function sanitizePwaButtonStyle(mixed $value): string
    {
        $allowed = ['float-bottom', 'float-bottom-right', 'float-bottom-left', 'float-bottom-center', 'beside-weather', 'beside-clock', 'above-header'];

        return in_array($value, $allowed, true) ? (string) $value : 'float-bottom-right';
    }

    public static function sanitizeCustomTimezone(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, timezone_identifiers_list(), true) ? $value : 'Asia/Jakarta';
    }

    public static function sanitizeClockSeparator(mixed $value): string
    {
        return in_array($value, ['colon', 'dot'], true) ? (string) $value : 'colon';
    }

    public static function sanitizeDateFormat(mixed $value): string
    {
        $allowed = ['l, j F Y', 'j F Y', 'D, j M Y', 'j/m/Y'];

        return in_array($value, $allowed, true) ? (string) $value : 'l, j F Y';
    }

    /**
     * @return array<string, string>
     */
    public static function timezoneChoices(): array
    {
        $zones = timezone_identifiers_list();

        return array_combine($zones, $zones);
    }

    public static function sanitizeExcerptLength(mixed $value): int
    {
        $length = (int) $value;

        return max(10, min(200, $length));
    }

    public static function sanitizeLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'list', 'featured', 'newsletter'], true) ? (string) $value : 'grid';
    }

    public static function sanitizeAlignment(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? (string) $value : 'left';
    }

    public static function sanitizeOrder(mixed $value): string
    {
        return in_array($value, ['oldest', 'newest'], true) ? (string) $value : 'oldest';
    }

    public static function sanitizeSeparator(mixed $value): string
    {
        $allowed = ['›', '/', '»', '—'];

        return in_array($value, $allowed, true) ? (string) $value : '›';
    }

    public static function sanitizeVariant(mixed $value): string
    {
        $allowed = ['classic', 'compact', 'bold'];

        return in_array($value, $allowed, true) ? (string) $value : 'classic';
    }

    public static function sanitizeBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    public static function clampInt(mixed $value, int $min, int $max): int
    {
        return min($max, max($min, (int) $value));
    }

    public static function clampFloat(mixed $value, float $min, float $max): float
    {
        return min($max, max($min, (float) $value));
    }
}

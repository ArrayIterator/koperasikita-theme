<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Footer;
use KoperasiKita\Features\MarketHeaderIndex;
use KoperasiKita\Features\Markets;
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
     * Size settings: theme-mod id => [CSS variable, default px, min px, max px].
     * Mirrors the kk_title_size / kk_card_title_size / kk_excerpt_size controls
     * in register(), the --kk-*-size defaults in tokens.css, and the ranges
     * of their sanitize callbacks. The article-list thumbnail size lives
     * here too (same mechanism, different section: Appearance &
     * Accessibility, not Typography). So do the mobile bottom-nav sizes
     * (Mobile Menu section, not Typography) and the single-post title
     * size (Single Post section, not Typography - it is its own
     * setting because the article title must be sized independently
     * of the page/archive title).
     */
    private const SIZE_SETTINGS = [
        'kk_title_size' => ['--kk-title-size', 40, 24, 72],
        'kk_single_title_size' => ['--kk-single-title-size', 40, 24, 72],
        'kk_card_title_size' => ['--kk-card-title-size', 20, 14, 32],
        'kk_excerpt_size' => ['--kk-excerpt-size', 14, 12, 20],
        'kk_list_thumbnail_size' => ['--kk-list-thumb-size', 180, 80, 200],
        'kk_mobile_menu_icon_size' => ['--kk-mobile-menu-icon-size', 18, 16, 32],
        'kk_mobile_menu_title_size' => ['--kk-mobile-menu-title-size', 11, 10, 16],
    ];

    /**
     * Title / excerpt line clamps (Customizer -> Appearance &
     * Accessibility). Same shape as SIZE_SETTINGS but unitless line
     * counts - the values feed --kk-title-clamp / --kk-excerpt-clamp,
     * which the -webkit-line-clamp rules read via var().
     */
    private const CLAMP_SETTINGS = [
        'kk_title_clamp' => ['--kk-title-clamp', 2, 1, 5],
        'kk_excerpt_clamp' => ['--kk-excerpt-clamp', 2, 1, 5],
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
            'koperasikita_logo' => esc_html__('Logo', 'koperasikita'),
            'koperasikita_advanced_logo' => esc_html__('Advanced Logo & Sidebar', 'koperasikita'),
            'koperasikita_typography' => esc_html__('Typography', 'koperasikita'),
            'koperasikita_header' => esc_html__('Header & Breaking News', 'koperasikita'),
            'koperasikita_market_header' => esc_html__('Market Index', 'koperasikita'),
            'koperasikita_mobile_menu' => esc_html__('Mobile Menu', 'koperasikita'),
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

        self::registerMarketHeader($wpCustomize);

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
            static fn ($value) => self::clampInt($value, 1, 20),
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_count', [
            'label' => esc_html__('Hero Posts', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 20],
        ]);

        /* Hero header + source: a Hero Presentation switch (12 modes:
         * grid/slider, with or without a widget area beside the hero),
         * and a Latest/Category source (the category picker only
         * matters when the source is Category). No title/eyebrow
         * here: the hero renders with no heading, exactly as
         * before - the heading belongs to the sections below it. */
        self::addSetting(
            $wpCustomize,
            'kk_hero_mode',
            'grid-left',
            [self::class, 'sanitizeHeroMode'],
            'refresh'
        );
        $choices = [];
        foreach (\KoperasiKita\Features\Hero::modes() as $slug => $label) {
            $choices[$slug] = esc_html__($label, 'koperasikita');
        }
        $wpCustomize->add_control('kk_hero_mode', [
            'label' => esc_html__('Hero Presentation', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => $choices,
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_hero_source',
            'latest',
            [self::class, 'sanitizeHeroSource'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_source', [
            'label' => esc_html__('Hero Source', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'latest' => esc_html__('Latest posts', 'koperasikita'),
                'category' => esc_html__('Selected categories', 'koperasikita'),
                'tag' => esc_html__('Selected tags', 'koperasikita'),
            ],
        ]);

        /* Hero cards (the cards beside the lead in the Leads + Cards
         * modes): which parts show, and where the title sits. */
        $hero_card_toggles = [
            'kk_hero_card_show_title' => ['Show Title', true],
            'kk_hero_card_show_category' => ['Show Category', true],
            'kk_hero_card_show_date' => ['Show Date', true],
            'kk_hero_card_show_excerpt' => ['Show Excerpt', false],
        ];
        foreach ($hero_card_toggles as $id => [$label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
            ]);
        }

        self::addSetting(
            $wpCustomize,
            'kk_hero_card_title_position',
            'below',
            [self::class, 'sanitizeHeroCardTitlePosition'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_card_title_position', [
            'label' => esc_html__('Card Title Position', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'below' => esc_html__('Title Below', 'koperasikita'),
                'above' => esc_html__('Title Above', 'koperasikita'),
                'inside' => esc_html__('Title Inside Featured Image', 'koperasikita'),
            ],
        ]);

        /* Slider style: Classic (image on top, title + meta + excerpt
         * below) or Inner (title + meta + excerpt inside the image,
         * overlaid with a gradient). Plus which parts show, and
         * auto-slide with an interval. These only show while the
         * Hero Presentation is a slider mode. */
        self::addSetting(
            $wpCustomize,
            'kk_hero_slider_style',
            'classic',
            [self::class, 'sanitizeHeroSliderStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_slider_style', [
            'label' => esc_html__('Slider Style', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'classic' => esc_html__('Classic', 'koperasikita'),
                'inner' => esc_html__('Inner (Title Inside Image)', 'koperasikita'),
            ],
            'active_callback' => [self::class, 'isHeroSlider'],
        ]);

        $hero_slider_toggles = [
            'kk_hero_slider_show_category' => ['Show Category', true],
            'kk_hero_slider_show_date' => ['Show Date', true],
            'kk_hero_slider_show_excerpt' => ['Show Excerpt', true],
        ];
        foreach ($hero_slider_toggles as $id => [$label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
                'active_callback' => [self::class, 'isHeroSlider'],
            ]);
        }

        self::addSetting(
            $wpCustomize,
            'kk_hero_slider_auto',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_slider_auto', [
            'label' => esc_html__('Auto Slide', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isHeroSlider'],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_hero_slider_interval',
            5,
            static fn ($value) => self::clampInt($value, 2, 30),
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_slider_interval', [
            'label' => esc_html__('Auto Slide Interval (seconds)', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 2, 'max' => 30],
            'active_callback' => [self::class, 'isHeroSlider'],
        ]);

        /* Bottom Border: the rule under the hero that separates it
         * from the content below. Classic is a single 1px divider;
         * Double is the newspaper double rule; No Border leaves
         * the hero flowing straight into the content. */
        self::addSetting(
            $wpCustomize,
            'kk_hero_bottom_border',
            'classic',
            [self::class, 'sanitizeHeroBottomBorder'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_bottom_border', [
            'label' => esc_html__('Bottom Border', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'classic' => esc_html__('Classic', 'koperasikita'),
                'no-border' => esc_html__('No Border', 'koperasikita'),
                'double' => esc_html__('Double', 'koperasikita'),
            ],
        ]);

        /* Lead options (grid modes): whether the lead title sits
         * inside the featured image or below it, and which parts
         * show. Only shown while the Hero Presentation is a grid. */
        self::addSetting(
            $wpCustomize,
            'kk_hero_lead_title_position',
            'below',
            [self::class, 'sanitizeHeroLeadTitlePosition'],
            'refresh'
        );
        $wpCustomize->add_control('kk_hero_lead_title_position', [
            'label' => esc_html__('Lead Title Position', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'below' => esc_html__('Title Below', 'koperasikita'),
                'inside' => esc_html__('Title Inside Featured Image', 'koperasikita'),
            ],
            'active_callback' => [self::class, 'isHeroGrid'],
        ]);

        $hero_lead_toggles = [
            'kk_hero_lead_show_category' => ['Show Category', true],
            'kk_hero_lead_show_date' => ['Show Date', true],
            'kk_hero_lead_show_excerpt' => ['Show Excerpt', true],
        ];
        foreach ($hero_lead_toggles as $id => [$label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
                'active_callback' => [self::class, 'isHeroGrid'],
            ]);
        }

        self::addSetting(
            $wpCustomize,
            'kk_hero_category_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_hero_category_ids', [
            'label' => esc_html__('Hero Categories', 'koperasikita'),
            'description' => esc_html__('Used when the hero source is Selected categories. Leave it empty to fall back to the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'taxonomy' => 'category',
        ]));

        self::addSetting(
            $wpCustomize,
            'kk_hero_tag_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_hero_tag_ids', [
            'label' => esc_html__('Hero Tags', 'koperasikita'),
            'description' => esc_html__('Used when the hero source is Selected tags. Leave it empty to fall back to the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'taxonomy' => 'post_tag',
        ]));

        // Highlights ("Sorotan"): editor-picked posts filtered by
        // category and/or tag, with a custom title, a post count and
        // a Grid/Slider layout. Empty filters = the latest posts, so
        // the section never shows a hole.
        self::addSetting(
            $wpCustomize,
            'kk_highlights_enabled',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_enabled', [
            'label' => esc_html__('Show Highlights (Sorotan)', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_highlights_title',
            __('Sorotan', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_title', [
            'label' => esc_html__('Highlights Title', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_highlights_show_title',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_show_title', [
            'label' => esc_html__('Enable Title', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
        ]);

        /* Item Style: how each highlight looks - thumbnail with the
         * title inside it, thumbnail with the title below, or title
         * with meta and no thumbnail. */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_item_style',
            'thumb-below',
            [self::class, 'sanitizeHighlightsItemStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_item_style', [
            'label' => esc_html__('Item Style', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'thumb-inside' => esc_html__('Thumb Title Inside', 'koperasikita'),
                'thumb-below' => esc_html__('Thumb With Title Below', 'koperasikita'),
                'title-meta' => esc_html__('Title With Meta (No Thumb)', 'koperasikita'),
            ],
        ]);

        /* Layout Style: the section container - classic (border top
         * and bottom), no border, or a box with a paper background. */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_layout_style',
            'classic',
            [self::class, 'sanitizeHighlightsLayoutStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_layout_style', [
            'label' => esc_html__('Layout Style', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'classic' => esc_html__('Classic', 'koperasikita'),
                'no-border' => esc_html__('No Border', 'koperasikita'),
                'box' => esc_html__('Box', 'koperasikita'),
            ],
        ]);

        /* Display: Slider Grid (grid look, swipeable with next/prev,
         * autoplay optional), Slider Full (one article at a time,
         * small thumbnail + title, autoplay optional), or List
         * (widget-like rows split into columns). */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_display',
            'slider-grid',
            [self::class, 'sanitizeHighlightsDisplay'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_display', [
            'label' => esc_html__('Display', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'slider-grid' => esc_html__('Slider Grid', 'koperasikita'),
                'slider-full' => esc_html__('Slider Full', 'koperasikita'),
                'list' => esc_html__('List', 'koperasikita'),
            ],
        ]);

        /* Slider Grid: Autoplay (None by default - the grid slides
         * on touch / prev-next, it never advances on its own) and
         * the interval, shown only while Autoplay is on. */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_slider_grid_autoplay',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_slider_grid_autoplay', [
            'label' => esc_html__('Slider Grid Autoplay', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isHighlightsSliderGrid'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_highlights_slider_grid_interval',
            5,
            static fn ($value) => self::clampInt($value, 2, 30),
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_slider_grid_interval', [
            'label' => esc_html__('Slider Grid Autoplay Interval (seconds)', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 2, 'max' => 30],
            'active_callback' => [self::class, 'isHighlightsSliderGridAutoplay'],
        ]);

        /* Slider Full: Autoplay (None by default) and the interval,
         * shown only while Autoplay is on. */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_slider_full_autoplay',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_slider_full_autoplay', [
            'label' => esc_html__('Slider Full Autoplay', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isHighlightsSliderFull'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_highlights_slider_full_interval',
            5,
            static fn ($value) => self::clampInt($value, 2, 30),
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_slider_full_interval', [
            'label' => esc_html__('Slider Full Autoplay Interval (seconds)', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 2, 'max' => 30],
            'active_callback' => [self::class, 'isHighlightsSliderFullAutoplay'],
        ]);

        /* List: how many columns the widget-like rows split into,
         * how many rows each column shows, and whether the columns
         * slide (prev/next + touch) instead of sitting still. */
        self::addSetting(
            $wpCustomize,
            'kk_highlights_list_columns',
            2,
            static fn ($value) => self::clampInt($value, 1, 4),
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_list_columns', [
            'label' => esc_html__('List Columns', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 4],
            'active_callback' => [self::class, 'isHighlightsList'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_highlights_list_rows',
            3,
            static fn ($value) => self::clampInt($value, 1, 10),
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_list_rows', [
            'label' => esc_html__('List Rows (per column)', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 10],
            'active_callback' => [self::class, 'isHighlightsList'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_highlights_list_enable_slider',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_list_enable_slider', [
            'label' => esc_html__('List Enable Slider', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isHighlightsList'],
        ]);

        /* Which parts each highlight card shows (same toggles as the
         * hero cards and slider). */
        $highlights_toggles = [
            'kk_highlights_show_category' => ['Show Category', true],
            'kk_highlights_show_date' => ['Show Date', true],
            'kk_highlights_show_excerpt' => ['Show Excerpt', false],
        ];
        foreach ($highlights_toggles as $id => [$label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
            ]);
        }

        self::addSetting(
            $wpCustomize,
            'kk_highlights_count',
            3,
            static fn ($value) => self::clampInt($value, 1, 9),
            'refresh'
        );
        $wpCustomize->add_control('kk_highlights_count', [
            'label' => esc_html__('Highlights Posts', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 9],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_highlights_category_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_highlights_category_ids', [
            'label' => esc_html__('Highlights Categories', 'koperasikita'),
            'description' => esc_html__('Posts from these categories (or any of the tags below). Leave both empty for the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'taxonomy' => 'category',
        ]));

        self::addSetting(
            $wpCustomize,
            'kk_highlights_tag_ids',
            [],
            [self::class, 'sanitizeTermIds'],
            'refresh'
        );
        $wpCustomize->add_control(new TermMultiSelectControl($wpCustomize, 'kk_highlights_tag_ids', [
            'label' => esc_html__('Highlights Tags', 'koperasikita'),
            'description' => esc_html__('Posts with any of these tags (or any of the categories above). Leave both empty for the latest posts.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'taxonomy' => 'post_tag',
        ]));

        /* Latest-news heading: an editable title and eyebrow for the
         * Latest News section (was a hardcoded "Latest News"). */
        self::addSetting(
            $wpCustomize,
            'kk_latest_title',
            __('Latest News', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_latest_title', [
            'label' => esc_html__('Latest News Title', 'koperasikita'),
            'description' => esc_html__('The heading of the latest-news section. Leave it empty to hide the heading.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_latest_eyebrow',
            '',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_latest_eyebrow', [
            'label' => esc_html__('Latest News Eyebrow', 'koperasikita'),
            'description' => esc_html__('The small line above the latest-news title. Leave it empty to hide it.', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'text',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_homepage_layout',
            'grid',
            [self::class, 'sanitizeLatestLayout'],
            'postMessage'
        );
        $wpCustomize->add_control('kk_homepage_layout', [
            'label' => esc_html__('Homepage Layout', 'koperasikita'),
            'section' => 'koperasikita_homepage',
            'type' => 'select',
            'choices' => [
                'grid' => esc_html__('Grid', 'koperasikita'),
                'list' => esc_html__('List', 'koperasikita'),
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
        // The card parts below are global for ALL category blocks
        // (same pattern as the Hero card toggles): whether each
        // category-block card shows its category eyebrow and its date.
        $category_block_toggles = [
            'kk_category_block_show_category' => ['Show Category', true],
            'kk_category_block_show_date' => ['Show Date', true],
        ];
        foreach ($category_block_toggles as $id => [$label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
            ]);
        }

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
                    'featured-right' => esc_html__('Featured Right', 'koperasikita'),
                    'list-columns' => esc_html__('List (Columns)', 'koperasikita'),
                ],
            ]);

            /* List (Columns): how many columns the widget-like rows
             * split into, how many rows each column shows, and
             * whether the columns slide (prev/next + touch). Only
             * shown while this slot's Display Style is List
             * (Columns). */
            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_list_columns',
                2,
                static fn ($value) => self::clampInt($value, 1, 4),
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_list_columns', [
                'label' => sprintf(esc_html__('Category Block %d: List Columns', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'number',
                'input_attrs' => ['min' => 1, 'max' => 4],
                'active_callback' => static fn () => self::isCategoryListColumns($slot),
            ]);
            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_list_rows',
                3,
                static fn ($value) => self::clampInt($value, 1, 10),
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_list_rows', [
                'label' => sprintf(esc_html__('Category Block %d: List Rows (per column)', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'number',
                'input_attrs' => ['min' => 1, 'max' => 10],
                'active_callback' => static fn () => self::isCategoryListColumns($slot),
            ]);
            self::addSetting(
                $wpCustomize,
                'kk_homepage_category_' . $slot . '_list_enable_slider',
                false,
                [self::class, 'sanitizeBool'],
                'refresh'
            );
            $wpCustomize->add_control('kk_homepage_category_' . $slot . '_list_enable_slider', [
                'label' => sprintf(esc_html__('Category Block %d: List Enable Slider', 'koperasikita'), $slot),
                'section' => 'koperasikita_homepage',
                'type' => 'checkbox',
                'active_callback' => static fn () => self::isCategoryListColumns($slot),
            ]);
        }

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
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'load-more' => esc_html__('Load More', 'koperasikita'),
                'pagination' => esc_html__('Numbered Pagination', 'koperasikita'),
                'next-prev' => esc_html__('Next Prev', 'koperasikita'),
            ],
        ]);

        /* How the Previous / Next links look in the numbered and
         * next-prev pagination: words ("Newer" / "Older") or just
         * the double-chevron icons (<< >>). */
        self::addSetting(
            $wpCustomize,
            'kk_pagination_nav_style',
            'text',
            [self::class, 'sanitizePaginationNavStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pagination_nav_style', [
            'label' => esc_html__('Pagination Prev / Next Style', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'text' => esc_html__('Text', 'koperasikita'),
                'icons' => esc_html__('Icons', 'koperasikita'),
            ],
        ]);

        /* Which icon the Prev / Next links use when the style above
         * is Icons. A plain select (not the menu icon picker) is the
         * Customizer-friendly shape; the defaults are already set so
         * the icons work out of the box. */
        $nav_icons = [
            'chevrons-left' => esc_html__('Double chevron left', 'koperasikita'),
            'chevron-left' => esc_html__('Chevron left', 'koperasikita'),
            'arrow-left' => esc_html__('Arrow left', 'koperasikita'),
            'arrow-left-circle' => esc_html__('Arrow left (circle)', 'koperasikita'),
            'prev' => esc_html__('Skip back', 'koperasikita'),
        ];
        self::addSetting(
            $wpCustomize,
            'kk_pagination_prev_icon',
            'chevrons-left',
            [self::class, 'sanitizePaginationIcon'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pagination_prev_icon', [
            'label' => esc_html__('Pagination Prev Icon', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => $nav_icons,
            'active_callback' => [self::class, 'isPaginationNavIcons'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_pagination_next_icon',
            'chevrons-right',
            [self::class, 'sanitizePaginationIcon'],
            'refresh'
        );
        $wpCustomize->add_control('kk_pagination_next_icon', [
            'label' => esc_html__('Pagination Next Icon', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'chevrons-right' => esc_html__('Double chevron right', 'koperasikita'),
                'chevron-right' => esc_html__('Chevron right', 'koperasikita'),
                'arrow-right' => esc_html__('Arrow right', 'koperasikita'),
                'arrow-right-circle' => esc_html__('Arrow right (circle)', 'koperasikita'),
                'next' => esc_html__('Skip forward', 'koperasikita'),
            ],
            'active_callback' => [self::class, 'isPaginationNavIcons'],
        ]);

        /* Load More: the button text, what shows while loading, and
         * how many times load-more may load before the normal
         * numbered navigation takes over. Each load fetches one more
         * page. Max 0 means unlimited. */
        self::addSetting(
            $wpCustomize,
            'kk_load_more_text',
            'Load more',
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_load_more_text', [
            'label' => esc_html__('Load More Button Text', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'text',
            'active_callback' => [self::class, 'isLoadMoreMode'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_load_more_loading_style',
            'icon-text',
            [self::class, 'sanitizeLoadMoreLoadingStyle'],
            'refresh'
        );
        $wpCustomize->add_control('kk_load_more_loading_style', [
            'label' => esc_html__('Load More Loading Display', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'icon-text' => esc_html__('Icon + Text', 'koperasikita'),
                'icon' => esc_html__('Icon Only', 'koperasikita'),
                'text' => esc_html__('Text "Loading"', 'koperasikita'),
            ],
            'active_callback' => [self::class, 'isLoadMoreMode'],
        ]);
        self::addSetting(
            $wpCustomize,
            'kk_load_more_max_pages',
            3,
            static fn ($value) => self::clampInt($value, 0, 10),
            'refresh'
        );
        $wpCustomize->add_control('kk_load_more_max_pages', [
            'label' => esc_html__('Load More Max Pages', 'koperasikita'),
            'description' => esc_html__('How many times Load More may load. Each load fetches one more page. After that the link navigates to the next page instead. 0 means unlimited.', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'number',
            'input_attrs' => ['min' => 0, 'max' => 10],
            'active_callback' => [self::class, 'isLoadMoreMode'],
        ]);

        /* Title / excerpt line clamps: how many lines a post title or
         * excerpt may wrap to in cards and lists before the ellipsis
         * cuts it. Long titles that overflow on small phones (the
         * search results especially) stay inside the card. Default 2. */
        $clamp_controls = [
            'kk_title_clamp' => ['Title Clamp', 2],
            'kk_excerpt_clamp' => ['Excerpt Clamp', 2],
        ];
        foreach ($clamp_controls as $id => [$label, $default]) {
            self::addSetting(
                $wpCustomize,
                $id,
                $default,
                static fn ($value) => self::clampInt($value, 1, 5),
                'postMessage'
            );
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => 'koperasikita_accessibility',
                'type' => 'number',
                'input_attrs' => ['min' => 1, 'max' => 5],
            ]);
        }

        /* Mobile text size: the body font size on phones (max-width
         * 767px). The desktop body size stays 16px; this only ever
         * moves the mobile size, so a bigger phone size never blows
         * up the desktop layout. Default 16. */
        self::addSetting(
            $wpCustomize,
            'kk_mobile_font_size',
            16,
            static fn ($value) => self::clampInt($value, 12, 20),
            'refresh'
        );
        $wpCustomize->add_control('kk_mobile_font_size', [
            'label' => esc_html__('Mobile Text Size', 'koperasikita'),
            'description' => esc_html__('Body text size on phones, in pixels. Default 16.', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'number',
            'input_attrs' => ['min' => 12, 'max' => 20],
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

        /* Default colour scheme: the scheme a first-time visitor (or
         * a visitor who never touched the toggle) sees. System follows
         * the OS; Light/Dark pin it. A stored toggle choice always
         * wins over this default. */
        self::addSetting(
            $wpCustomize,
            'kk_color_scheme',
            'light',
            [self::class, 'sanitizeColorScheme'],
            'refresh'
        );
        $wpCustomize->add_control('kk_color_scheme', [
            'label' => esc_html__('Default Colour Scheme', 'koperasikita'),
            'description' => esc_html__('The colour scheme for visitors who have not picked one yet. System follows the device setting.', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'select',
            'choices' => [
                'system' => esc_html__('System (follow device)', 'koperasikita'),
                'light' => esc_html__('Light', 'koperasikita'),
                'dark' => esc_html__('Dark', 'koperasikita'),
            ],
        ]);

        /* Dark-mode switch visibility: hide both toggles (header on
         * desktop, drawer footer on mobile) and the site stays on the
         * default scheme. */
        self::addSetting(
            $wpCustomize,
            'kk_dark_mode_toggle',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_dark_mode_toggle', [
            'label' => esc_html__('Show Dark-Mode Switch', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'checkbox',
        ]);

        /* Back to top: the floating button that appears after
         * scrolling down (footer.php). Off hides it entirely. */
        self::addSetting(
            $wpCustomize,
            'kk_back_to_top',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_back_to_top', [
            'label' => esc_html__('Show Back to Top Button', 'koperasikita'),
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

        /* Master feature toggles. The Header toggles (ticker, weather)
         * are NOT in this list: each one is registered inline, right
         * before its own settings, so the Customizer shows the toggle
         * followed by its settings - never another checkbox. */
        $toggles = [
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

        /* Header: News Ticker group */
        /* Toggle first, then its settings in order. Each setting hides
         * when the ticker is off (active_callback), so a disabled
         * ticker leaves no orphan settings behind. */
        self::addSetting($wpCustomize, 'kk_ticker_enabled', true, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_ticker_enabled', [
            'label' => esc_html__('Enable News Ticker', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'checkbox',
            'priority' => 10,
        ]);

        /* Header: Weather group */
        /* Toggle first, then its settings in order. Each setting hides
         * when weather is off (active_callback). */
        self::addSetting($wpCustomize, 'kk_weather_enabled', true, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_weather_enabled', [
            'label' => esc_html__('Enable Weather', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'checkbox',
            'priority' => 20,
        ]);

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
            'active_callback' => [self::class, 'isWeatherEnabled'],
            'priority' => 21,
            'input_attrs' => [
                'min' => 0,
                'max' => 1440,
                'step' => 1,
            ],
        ]);

        /* Weather temperature unit: the top bar and the weather
         * widget show degC by default; Fahrenheit converts the
         * Celsius values the API returns. */
        self::addSetting(
            $wpCustomize,
            'kk_weather_unit',
            'celsius',
            [self::class, 'sanitizeWeatherUnit'],
            'refresh'
        );
        $wpCustomize->add_control('kk_weather_unit', [
            'label' => esc_html__('Weather Temperature Unit', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'active_callback' => [self::class, 'isWeatherEnabled'],
            'priority' => 22,
            'choices' => [
                'celsius' => esc_html__('Celsius (°C)', 'koperasikita'),
                'fahrenheit' => esc_html__('Fahrenheit (°F)', 'koperasikita'),
            ],
        ]);

        /* Mobile menu slide side: the drawer slides in from the left
         * by default; from the right when the hamburger sits on the
         * right or the layout reads better that way. */
        self::addSetting(
            $wpCustomize,
            'kk_mobile_menu_side',
            'left',
            [self::class, 'sanitizeMenuSide'],
            'refresh'
        );
        $wpCustomize->add_control('kk_mobile_menu_side', [
            'label' => esc_html__('Mobile Menu Slide Side', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'priority' => 40,
            'choices' => [
                'left' => esc_html__('Left', 'koperasikita'),
                'right' => esc_html__('Right', 'koperasikita'),
            ],
        ]);

        /* Mobile bottom nav (phones only): the master switch gates every
         * other setting in this section - while the bar is off, the
         * icon size, count and title settings stay hidden. The count
         * defaults to 5 (five items fit a phone width with room for a
         * thumb; 10 is the hard ceiling before the labels crush) and
         * the title size only shows while titles are on. */
        self::addSetting($wpCustomize, 'kk_mobile_menu_display', true, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_mobile_menu_display', [
            'label' => esc_html__('Display Mobile Menu', 'koperasikita'),
            'section' => 'koperasikita_mobile_menu',
            'type' => 'checkbox',
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_mobile_menu_icon_size',
            18,
            static fn ($value) => self::clampInt($value, 16, 32),
            'postMessage'
        );
        $wpCustomize->add_control('kk_mobile_menu_icon_size', [
            'label' => esc_html__('Icon Size', 'koperasikita'),
            'section' => 'koperasikita_mobile_menu',
            'type' => 'number',
            'input_attrs' => ['min' => 16, 'max' => 32],
            'active_callback' => [self::class, 'isMobileMenuDisplayed'],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_mobile_menu_max_count',
            5,
            static fn ($value) => self::clampInt($value, 1, 10),
            'refresh'
        );
        $wpCustomize->add_control('kk_mobile_menu_max_count', [
            'label' => esc_html__('Maximum Menu Count', 'koperasikita'),
            'section' => 'koperasikita_mobile_menu',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 10],
            'active_callback' => [self::class, 'isMobileMenuDisplayed'],
        ]);

        self::addSetting($wpCustomize, 'kk_mobile_menu_show_titles', true, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_mobile_menu_show_titles', [
            'label' => esc_html__('Display Menu Title', 'koperasikita'),
            'section' => 'koperasikita_mobile_menu',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isMobileMenuDisplayed'],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_mobile_menu_title_size',
            11,
            static fn ($value) => self::clampInt($value, 10, 16),
            'postMessage'
        );
        $wpCustomize->add_control('kk_mobile_menu_title_size', [
            'label' => esc_html__('Menu Title Size', 'koperasikita'),
            'section' => 'koperasikita_mobile_menu',
            'type' => 'number',
            'input_attrs' => ['min' => 10, 'max' => 16],
            'active_callback' => [self::class, 'isMobileMenuTitlesShown'],
        ]);

        /* The 4th element is the Header-section display priority
         * (feature-group order: top bar 30, clock 33, sticky nav 39).
         * Other sections keep the default priority. */
        $checkboxes = [
            'kk_single_featured' => ['koperasikita_single', 'Show Featured Image', true, 10],
            'kk_single_author_box' => ['koperasikita_single', 'Show Author Box', true, 10],
            'kk_single_related' => ['koperasikita_single', 'Show Related News', true, 10],
            'kk_single_reading_time' => ['koperasikita_single', 'Show Reading Time', true, 10],
            'kk_single_show_share' => ['koperasikita_single', 'Show Share Buttons', true, 10],
            'kk_agenda_location_enabled' => ['koperasikita_single', 'Show Event Location on Agenda Pages', true, 10],
            'kk_comments_avatars' => ['koperasikita_comments', 'Show Comment Avatars', true, 10],
            'kk_images_lazy' => ['koperasikita_media', 'Lazy-Load Images', true, 10],
            'kk_images_srcset' => ['koperasikita_media', 'Enable Responsive Images', true, 10],
            'kk_breadcrumb_enabled' => ['koperasikita_breadcrumb', 'Show Breadcrumbs', true, 10],
            'kk_breadcrumb_home' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on the Homepage', false, 10],
            'kk_breadcrumb_post' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on Articles', true, 10],
            'kk_breadcrumb_page' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on Pages', true, 10],
            'kk_breadcrumb_archive' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on Archives', true, 10],
            'kk_breadcrumb_search' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on Search Results', true, 10],
            'kk_breadcrumb_404' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on 404 Pages', true, 10],
            'kk_topbar_enabled' => ['koperasikita_header', 'Show Top Bar', true, 30],
            'kk_clock_enabled' => ['koperasikita_header', 'Show Live Clock in Top Bar', true, 33],
            'kk_clock_seconds' => ['koperasikita_header', 'Show Seconds on the Clock', false, 34],
            'kk_clock_24h' => ['koperasikita_header', 'Use the 24-Hour Clock', true, 35],
            'kk_clock_show_date' => ['koperasikita_header', 'Show Date on the Clock', true, 36],
            'kk_sticky_header' => ['koperasikita_header', 'Sticky Header Navigation', true, 41],
            'kk_list_show_time' => ['koperasikita_accessibility', 'Show Time on Article List & Archive', false, 10],
            'kk_single_show_time' => ['koperasikita_accessibility', 'Show Time on Single Post & Page', false, 10],
        ];

        foreach ($checkboxes as $id => [$section, $label, $default, $priority]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => $section,
                'type' => 'checkbox',
                'priority' => $priority,
            ]);
        }

        /* Article List Thumbnail Size: the thumbnail column width
         * in article list rows (Latest News list, Category Block
         * list and List (Columns), archive and blog lists).
         * Lives in Appearance & Accessibility with the other
         * article-list settings. The value is printed as the
         * --kk-list-thumb-size CSS variable (see SIZE_SETTINGS). */
        self::addSetting(
            $wpCustomize,
            'kk_list_thumbnail_size',
            180,
            static fn ($value) => self::clampInt($value, 80, 200),
            'refresh'
        );
        $wpCustomize->add_control('kk_list_thumbnail_size', [
            'label' => esc_html__('Article List Thumbnail Size (px)', 'koperasikita'),
            'section' => 'koperasikita_accessibility',
            'type' => 'number',
            'input_attrs' => ['min' => 80, 'max' => 200],
        ]);

        /* Single-post title size: the article title (single.php and
         * single-agenda.php) has its own setting in the Single Post
         * section. It used to share --kk-title-size with the
         * page/archive title, so the two could never be sized apart. */
        self::addSetting(
            $wpCustomize,
            'kk_single_title_size',
            40,
            static fn ($value) => self::clampInt($value, 24, 72),
            'postMessage'
        );
        $wpCustomize->add_control('kk_single_title_size', [
            'label' => esc_html__('Single Post Title Size', 'koperasikita'),
            'section' => 'koperasikita_single',
            'type' => 'number',
            'input_attrs' => ['min' => 24, 'max' => 72],
        ]);

        /* Related-post count: how many related stories the single
         * post shows (was a hardcoded 3). */
        self::addSetting(
            $wpCustomize,
            'kk_single_related_count',
            4,
            static fn ($value) => self::clampInt($value, 1, 6),
            'refresh'
        );
        $wpCustomize->add_control('kk_single_related_count', [
            'label' => esc_html__('Related News Posts', 'koperasikita'),
            'section' => 'koperasikita_single',
            'type' => 'number',
            'input_attrs' => ['min' => 1, 'max' => 6],
        ]);

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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 11,
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 12,
            'choices' => [
                'gold' => esc_html__('Gold (solid gold, black text)', 'koperasikita'),
                'green' => esc_html__('Green (brand green, white text)', 'koperasikita'),
                'dark' => esc_html__('Dark (near-black, white text)', 'koperasikita'),
                'red' => esc_html__('Red (alert red, white text)', 'koperasikita'),
                'outline' => esc_html__('Outline (gold border, dark text)', 'koperasikita'),
            ],
        ]);

        /* Agenda event location position: where the location picker
         * (venue + map link) is shown on a single agenda page. Only
         * shown when the location itself is enabled. */
        self::addSetting(
            $wpCustomize,
            'kk_agenda_location_position',
            'below-info',
            [self::class, 'sanitizeAgendaLocationPosition'],
            'refresh'
        );
        $wpCustomize->add_control('kk_agenda_location_position', [
            'label' => esc_html__('Event Location Position', 'koperasikita'),
            'description' => esc_html__('Where the event location is shown on a single agenda page.', 'koperasikita'),
            'section' => 'koperasikita_single',
            'type' => 'select',
            'active_callback' => [self::class, 'isAgendaLocationEnabled'],
            'priority' => 11,
            'choices' => [
                'below-info' => esc_html__('Below Agenda Info', 'koperasikita'),
                'above-info' => esc_html__('Above Agenda Info', 'koperasikita'),
                'below-article' => esc_html__('Below the Article', 'koperasikita'),
                'below-thumbnail' => esc_html__('Below the Thumbnail', 'koperasikita'),
                'before-article' => esc_html__('Before the Article (after Text Resizer)', 'koperasikita'),
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 13,
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 14,
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 15,
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 16,
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
            'active_callback' => [self::class, 'isTickerEnabled'],
            'priority' => 17,
            'input_attrs' => ['min' => 1, 'max' => 10],
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_clock_date_format',
            'full',
            [self::class, 'sanitizeClockDateFormat'],
            'refresh'
        );
        $wpCustomize->add_control('kk_clock_date_format', [
            'label' => esc_html__('Date Format', 'koperasikita'),
            'section' => 'koperasikita_header',
            'type' => 'select',
            'priority' => 37,
            'active_callback' => [self::class, 'isClockDateShown'],
            'choices' => [
                'full' => esc_html__('Full', 'koperasikita'),
                'short' => esc_html__('Short', 'koperasikita'),
                'numeric' => esc_html__('Numeric', 'koperasikita'),
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
            'priority' => 38,
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
            'priority' => 39,
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
            'priority' => 40,
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
            get_bloginfo('name'),
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
            get_bloginfo('name'),
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
            __('News, announcements and market updates from {app_name}.', 'koperasikita'),
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
            'description' => esc_html__('The app logo used when installing the PWA (manifest icons and home-screen icon). Defaults to the Koperasi Kita logo. Use a square image, at least 512×512, to replace it.', 'koperasikita'),
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

        // Whether the install prompt modal shows at all. Off leaves
        // the install button/banner working - only the popup is gone.
        self::addSetting($wpCustomize, 'kk_pwa_prompt_enabled', true, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_pwa_prompt_enabled', [
            'label' => esc_html__('Show Install Prompt', 'koperasikita'),
            'description' => esc_html__('Shows the install popup after the browser offers installation. Turn off to keep only the install button/banner.', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'checkbox',
        ]);

        // Prompt modal title and body text. {app_name} is replaced by
        // the app name. Shown only when the prompt is enabled.
        self::addSetting(
            $wpCustomize,
            'kk_pwa_prompt_title',
            __('Install {app_name}', 'koperasikita'),
            'sanitize_text_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_prompt_title', [
            'label' => esc_html__('Install Prompt Title', 'koperasikita'),
            'description' => esc_html__('Title of the install popup. {app_name} is replaced by the app name.', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'text',
            'active_callback' => static fn () => (bool) get_theme_mod('kk_pwa_prompt_enabled', true),
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_pwa_prompt_text',
            __('Add {app_name} to your home screen for faster access to news, announcements and market updates — even offline.', 'koperasikita'),
            'sanitize_textarea_field',
            'refresh'
        );
        $wpCustomize->add_control('kk_pwa_prompt_text', [
            'label' => esc_html__('Install Prompt Text', 'koperasikita'),
            'description' => esc_html__('Body text of the install popup. {app_name} is replaced by the app name.', 'koperasikita'),
            'section' => 'koperasikita_pwa',
            'type' => 'textarea',
            'active_callback' => static fn () => (bool) get_theme_mod('kk_pwa_prompt_enabled', true),
        ]);

        // Install screenshots for the manifest (shown by the browser
        // on the install prompt). Each slot takes MULTIPLE images: a
        // dynamic list - add as many as needed, remove any. An empty
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
         * served as a single request. On by default: one request
         * instead of ~13. Turn it off to debug the split files
         * (each file cached independently). */
        self::addSetting(
            $wpCustomize,
            'kk_combine_css',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_combine_css', [
            'label' => esc_html__('Use Combined CSS (one file)', 'koperasikita'),
            'description' => esc_html__('Combine all theme stylesheets into a single CSS file (one render-blocking request instead of ~13 chained ones). The file is rebuilt automatically when the theme is updated.', 'koperasikita'),
            'section' => 'koperasikita_advanced',
            'type' => 'checkbox',
        ]);

        /* Print the combined stylesheet inside the page instead of
         * loading it as a file. First paint is faster, but the CSS
         * is sent again with every page and cannot be cached, so
         * pages get heavier. Only worth it when the combined file
         * is small - the file stays the default. Shown only when
         * combined CSS is on. */
        self::addSetting(
            $wpCustomize,
            'kk_inline_css',
            false,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_inline_css', [
            'label' => esc_html__('Use Inline CSS', 'koperasikita'),
            'description' => esc_html__('Print the combined stylesheet inside the page instead of loading it as a file. First paint is faster, but the CSS is sent again with every page and cannot be cached, so pages get heavier. Only worth it when the combined file is small.', 'koperasikita'),
            'section' => 'koperasikita_advanced',
            'type' => 'checkbox',
            'active_callback' => static fn () => (bool) get_theme_mod('kk_combine_css', true),
        ]);

        /* Comments mode: Follow The Post (default) keeps each post /
         * page's own comment setting; Disable Comments closes
         * comments site-wide. */
        self::addSetting(
            $wpCustomize,
            'kk_comments_mode',
            'follow',
            [self::class, 'sanitizeCommentsMode'],
            'refresh'
        );
        $wpCustomize->add_control('kk_comments_mode', [
            'label' => esc_html__('Comments', 'koperasikita'),
            'section' => 'koperasikita_comments',
            'type' => 'select',
            'choices' => [
                'follow' => esc_html__('Enable (Follow The Post)', 'koperasikita'),
                'disabled' => esc_html__('Disable Comments', 'koperasikita'),
            ],
        ]);

        /* When the comments block hides: by the post/page's own
         * setting (default), when comments are disabled at all, or
         * always. */
        self::addSetting(
            $wpCustomize,
            'kk_comments_hide',
            'post',
            [self::class, 'sanitizeCommentsHide'],
            'refresh'
        );
        $wpCustomize->add_control('kk_comments_hide', [
            'label' => esc_html__('Hide Comments', 'koperasikita'),
            'section' => 'koperasikita_comments',
            'type' => 'select',
            'choices' => [
                'disabled' => esc_html__('Hide If comment disabled', 'koperasikita'),
                'post' => esc_html__('Hide If Comment disabled By the post / page', 'koperasikita'),
                'all' => esc_html__('Hide All', 'koperasikita'),
            ],
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

        /* Default thumbnail: the fallback image for posts without a
         * featured image (cards, hero, and the SEO/OG image). Leave
         * it empty to keep the shipped placeholder. */
        self::addSetting(
            $wpCustomize,
            'kk_default_thumbnail',
            0,
            'absint',
            'refresh'
        );
        $wpCustomize->add_control(new WP_Customize_Media_Control($wpCustomize, 'kk_default_thumbnail', [
            'label' => esc_html__('Default Thumbnail', 'koperasikita'),
            'description' => esc_html__('The image shown for posts without a featured image, and used as the SEO/social image when a post has none. Leave it empty to use the built-in placeholder.', 'koperasikita'),
            'section' => 'koperasikita_media',
            'mime_type' => 'image',
        ]));

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

        /* Auto Resolve ALT: the article featured image falls back
         * to the article title when the image itself has no ALT
         * text. Turn it off to leave the ALT empty instead. */
        self::addSetting(
            $wpCustomize,
            'kk_images_auto_alt',
            true,
            [self::class, 'sanitizeBool'],
            'refresh'
        );
        $wpCustomize->add_control('kk_images_auto_alt', [
            'label' => esc_html__('Auto Resolve ALT', 'koperasikita'),
            'description' => esc_html__('Use the article title as the featured image ALT text when the image has no ALT text of its own.', 'koperasikita'),
            'section' => 'koperasikita_media',
            'type' => 'checkbox',
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
     * :root rule would lose to them - while the preview's inline style on
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
     * Print the saved title/excerpt clamps onto the live site. Same
     * shape as outputTypographySizeOverrides but unitless line counts
     * (no 'px'): only values that differ from their defaults are
     * printed, as --kk-title-clamp / --kk-excerpt-clamp on :root.
     * The line-clamp rules read them via var().
     */
    public static function outputClampOverrides(): void
    {
        $declarations = '';

        foreach (self::CLAMP_SETTINGS as $id => [$variable, $default, $min, $max]) {
            $value = self::clampInt(get_theme_mod($id, $default), $min, $max);

            if ($value !== $default) {
                $declarations .= $variable . ':' . $value . ';';
            }
        }

        if ($declarations === '') {
            return;
        }

        wp_add_inline_style('kk-app', ':root{' . $declarations . '}');
    }

    /**
     * Print the saved mobile text size onto the live site. Only when
     * it differs from the 16px default: a media-query rule that sets
     * the body font size on phones (max-width 767px). The desktop
     * body size is untouched.
     */
    public static function outputMobileFontSizeOverride(): void
    {
        $size = self::clampInt(get_theme_mod('kk_mobile_font_size', 16), 12, 20);

        if ($size === 16) {
            return;
        }

        wp_add_inline_style('kk-app', '@media (max-width: 767px){body{font-size:' . $size . 'px}}');
    }

    /**
     * The Logo section (Customizer -> Logo). Runs on customize_register
     * at priority 20, after WordPress core registers the native
     * custom_logo control (priority 10), so the control can be moved
     * into this section.
     *
     * Order in the section: Mobile Logo, Mobile Logo Dark, Logo
     * (the native custom_logo), Dark Logo. Each variant is an image
     * media control storing an attachment ID; an unset variant falls
     * back to its counterpart (see Features\Logo).
     */
    public static function registerLogoControls(WP_Customize_Manager $wpCustomize): void
    {
        $controls = [
            'kk_logo_mobile' => [
                'label' => esc_html__('Mobile Logo', 'koperasikita'),
                'priority' => 10,
            ],
            'kk_logo_mobile_dark' => [
                'label' => esc_html__('Mobile Logo Dark', 'koperasikita'),
                'priority' => 20,
            ],
            'kk_logo_dark' => [
                'label' => esc_html__('Dark Logo', 'koperasikita'),
                'priority' => 40,
            ],
        ];

        foreach ($controls as $id => $args) {
            self::addSetting($wpCustomize, $id, 0, 'absint');
            $wpCustomize->add_control(new WP_Customize_Media_Control($wpCustomize, $id, [
                'label' => $args['label'],
                'section' => 'koperasikita_logo',
                'mime_type' => 'image',
                'priority' => $args['priority'],
            ]));
        }

        $logoControl = $wpCustomize->get_control('custom_logo');

        if ($logoControl) {
            $logoControl->section = 'koperasikita_logo';
            $logoControl->priority = 30;
        }
    }

    /**
     * Advanced logo & sidebar brand controls (section
     * koperasikita_advanced_logo): a square logo/icon (SVG upload is
     * allowed - see Logo::register, which only accepts sanitised
     * SVGs), an override that ignores the default logo, the text
     * brand (title + slogan), mobile centring / text hiding, and
     * how the drawer (mobile sidebar) brand renders. All defaults
     * keep the current look: no text brand, logo-only drawer.
     */
    public static function registerAdvancedLogoControls(WP_Customize_Manager $wpCustomize): void
    {
        self::addSetting($wpCustomize, 'kk_logo_ignore_default', false, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_logo_ignore_default', [
            'label' => esc_html__('Ignore Default Logo', 'koperasikita'),
            'description' => esc_html__('Ignore the default logo and use the square logo (or the built-in mark) instead.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'checkbox',
            'priority' => 10,
        ]);

        self::addSetting($wpCustomize, 'kk_logo_square', 0, 'absint');
        $wpCustomize->add_control(new WP_Customize_Media_Control($wpCustomize, 'kk_logo_square', [
            'label' => esc_html__('Square Logo / Icon', 'koperasikita'),
            'description' => esc_html__('A square mark for the drawer and as the masthead fallback. SVG files are accepted.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'mime_type' => 'image',
            'priority' => 20,
        ]));

        self::addSetting($wpCustomize, 'kk_logo_show_title', false, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_logo_show_title', [
            'label' => esc_html__('Show Title Text', 'koperasikita'),
            'description' => esc_html__('Show the site title as text next to the logo in the masthead.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'checkbox',
            'priority' => 30,
        ]);

        self::addSetting($wpCustomize, 'kk_logo_title_text', '', 'sanitize_text_field', 'refresh');
        $wpCustomize->add_control('kk_logo_title_text', [
            'label' => esc_html__('Logo Title Text', 'koperasikita'),
            'description' => esc_html__('Leave empty to use the site title.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'text',
            'priority' => 40,
            'active_callback' => [self::class, 'isLogoTitleShown'],
        ]);

        self::addSetting($wpCustomize, 'kk_logo_show_slogan', false, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_logo_show_slogan', [
            'label' => esc_html__('Show Slogan', 'koperasikita'),
            'description' => esc_html__('Show the subtitle under the title.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'checkbox',
            'priority' => 50,
            'active_callback' => [self::class, 'isLogoTitleShown'],
        ]);

        self::addSetting($wpCustomize, 'kk_logo_subtitle_text', '', 'sanitize_text_field', 'refresh');
        $wpCustomize->add_control('kk_logo_subtitle_text', [
            'label' => esc_html__('Logo Subtitle', 'koperasikita'),
            'description' => esc_html__('Leave empty to use the site tagline.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'text',
            'priority' => 60,
            'active_callback' => [self::class, 'isLogoSloganShown'],
        ]);

        /* Header logo position, separately for desktop and mobile.
         * 'left' is the default look (brand at the left, date at the
         * right). 'centered' / 'right' move the brand with auto
         * margins; 'hide' hides the brand (and the masthead with it,
         * so no empty band is left). This replaces the old
         * "Center Brand on Mobile" checkbox - centered is now just
         * one of the mobile position choices. */
        self::addSetting($wpCustomize, 'kk_logo_position_desktop', 'left', [self::class, 'sanitizeLogoPosition'], 'refresh');
        $wpCustomize->add_control('kk_logo_position_desktop', [
            'label' => esc_html__('Header Logo Position (Desktop)', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'select',
            'choices' => [
                'left' => esc_html__('Left', 'koperasikita'),
                'centered' => esc_html__('Centered', 'koperasikita'),
                'right' => esc_html__('Right', 'koperasikita'),
                'hide' => esc_html__('Hidden', 'koperasikita'),
            ],
            'priority' => 70,
        ]);

        self::addSetting($wpCustomize, 'kk_logo_position_mobile', 'left', [self::class, 'sanitizeLogoPosition'], 'refresh');
        $wpCustomize->add_control('kk_logo_position_mobile', [
            'label' => esc_html__('Header Logo Position (Mobile)', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'select',
            'choices' => [
                'left' => esc_html__('Left', 'koperasikita'),
                'centered' => esc_html__('Centered', 'koperasikita'),
                'right' => esc_html__('Right', 'koperasikita'),
                'hide' => esc_html__('Hidden', 'koperasikita'),
                'centered-bar' => esc_html__('Centered Bar', 'koperasikita'),
            ],
            'priority' => 75,
        ]);

        self::addSetting($wpCustomize, 'kk_logo_hide_text_mobile', false, [self::class, 'sanitizeBool'], 'refresh');
        $wpCustomize->add_control('kk_logo_hide_text_mobile', [
            'label' => esc_html__('Hide Text on Mobile', 'koperasikita'),
            'description' => esc_html__('Hide the title and slogan on mobile, logo only.', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'checkbox',
            'priority' => 80,
        ]);

        self::addSetting(
            $wpCustomize,
            'kk_logo_sidebar_mode',
            'logo-only',
            [self::class, 'sanitizeSidebarMode'],
            'refresh'
        );
        $wpCustomize->add_control('kk_logo_sidebar_mode', [
            'label' => esc_html__('Sidebar Brand', 'koperasikita'),
            'description' => esc_html__('How the brand renders in the drawer (mobile sidebar).', 'koperasikita'),
            'section' => 'koperasikita_advanced_logo',
            'type' => 'select',
            'choices' => [
                'logo-only' => esc_html__('Logo Only', 'koperasikita'),
                'logo-title' => esc_html__('Logo + Title', 'koperasikita'),
                'logo-title-slogan' => esc_html__('Logo + Title + Slogan', 'koperasikita'),
                'title-only' => esc_html__('Title Only', 'koperasikita'),
                'hidden' => esc_html__('Hidden', 'koperasikita'),
            ],
            'priority' => 90,
        ]);
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

    /* Feature-toggle active_callbacks: a feature's settings only
     * show while its master toggle is on. WordPress calls these with
     * the control; the return decides visibility. */
    public static function isTickerEnabled(): bool
    {
        return (bool) get_theme_mod('kk_ticker_enabled', true);
    }

    public static function isClockDateShown(): bool
    {
        return (bool) get_theme_mod('kk_clock_show_date', true);
    }

    public static function isWeatherEnabled(): bool
    {
        return (bool) get_theme_mod('kk_weather_enabled', true);
    }

    public static function isMarketHeaderEnabled(): bool
    {
        return MarketHeaderIndex::settings()['enabled'];
    }

    /* Market Index: the strip settings live in one option
     * (kk_market_header_index) shared with the admin page, so these
     * settings are 'option' type - the only option-type settings in
     * the Customizer. Transport is refresh: the strip renders
     * server-side, so a change reloads the preview with the new
     * strip (or none, when disabled). */
    private static function registerMarketHeader(WP_Customize_Manager $wpCustomize): void
    {
        $wpCustomize->add_setting('kk_market_header_index[enabled]', [
            'type' => 'option',
            'default' => true,
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeEnabled'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_setting('kk_market_header_index[items]', [
            'type' => 'option',
            'default' => MarketHeaderIndex::defaultItems(),
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeItems'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_setting('kk_market_header_index[cycle_interval]', [
            'type' => 'option',
            'default' => MarketHeaderIndex::DEFAULT_CYCLE_INTERVAL,
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeCycleInterval'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_setting('kk_market_header_index[snapshot_label]', [
            'type' => 'option',
            'default' => __('Market Snapshot', 'koperasikita'),
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeSnapshotLabel'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_setting('kk_market_header_index[snapshot_show_datetime]', [
            'type' => 'option',
            'default' => true,
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeSnapshotShowDatetime'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_setting('kk_market_header_index[snapshot_format]', [
            'type' => 'option',
            'default' => MarketHeaderIndex::DEFAULT_SNAPSHOT_FORMAT,
            'sanitize_callback' => [MarketHeaderIndex::class, 'sanitizeSnapshotFormat'],
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_control('kk_market_header_enabled', [
            'label' => esc_html__('Show the market index strip', 'koperasikita'),
            'description' => esc_html__('Shows the picked market indices in a strip below the header. Requires the Markets feature.', 'koperasikita'),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[enabled]',
            'type' => 'checkbox',
        ]);

        $wpCustomize->add_control('kk_market_header_snapshot_label', [
            'label' => esc_html__('Snapshot label', 'koperasikita'),
            'description' => esc_html(sprintf(
                /* translators: %s: the default snapshot label. */
                __('The text before the date and time. Leave empty to hide the label. Default: %s.', 'koperasikita'),
                __('Market Snapshot', 'koperasikita')
            )),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[snapshot_label]',
            'type' => 'text',
            'active_callback' => [self::class, 'isMarketHeaderEnabled'],
        ]);

        $wpCustomize->add_control('kk_market_header_snapshot_show_datetime', [
            'label' => esc_html__('Show day and time', 'koperasikita'),
            'description' => esc_html__('Shows the day and time the strip data was taken, after the label.', 'koperasikita'),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[snapshot_show_datetime]',
            'type' => 'checkbox',
            'active_callback' => [self::class, 'isMarketHeaderEnabled'],
        ]);

        $wpCustomize->add_control('kk_market_header_snapshot_format', [
            'label' => esc_html__('Day and time format', 'koperasikita'),
            'description' => esc_html(sprintf(
                /* translators: 1: the default date format, 2: an example rendered with it. */
                __('PHP date format for the snapshot day and time. Default: %1$s — for example: %2$s.', 'koperasikita'),
                MarketHeaderIndex::DEFAULT_SNAPSHOT_FORMAT,
                wp_date(MarketHeaderIndex::DEFAULT_SNAPSHOT_FORMAT, time())
            )),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[snapshot_format]',
            'type' => 'text',
            'active_callback' => [self::class, 'isMarketHeaderEnabled'],
        ]);

        $wpCustomize->add_control('kk_market_header_cycle_interval', [
            'label' => esc_html__('Seconds between items on mobile', 'koperasikita'),
            'description' => esc_html__('On small screens the strip shows one item at a time; this is how long each item shows.', 'koperasikita'),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[cycle_interval]',
            'type' => 'number',
            'input_attrs' => ['min' => 2, 'max' => 120],
            'active_callback' => [self::class, 'isMarketHeaderEnabled'],
        ]);

        $wpCustomize->add_control(new MarketItemsControl($wpCustomize, 'kk_market_header_items', [
            'label' => esc_html__('Market items', 'koperasikita'),
            'description' => esc_html__('Pick up to 16 items, in the order they show. Empty means the default set (IHSG, USD/IDR, Antam, Brent).', 'koperasikita'),
            'section' => 'koperasikita_market_header',
            'settings' => 'kk_market_header_index[items]',
            'active_callback' => [self::class, 'isMarketHeaderEnabled'],
        ]));
    }

    /* The slider settings only show while the Hero Presentation is
     * a slider mode (Slider One / Slider Full / Slider Center /
     * Slider + Widget). */
    public static function isHeroSlider(): bool
    {
        return \KoperasiKita\Features\Hero::isSlider(
            \KoperasiKita\Features\Hero::sanitize( get_theme_mod( 'kk_hero_mode', \KoperasiKita\Features\Hero::GRID_LEFT ) )
        );
    }

    /* The lead settings only show while the Hero Presentation is a
     * grid mode (Grid Left / Right / Center / Grid + Widget /
     * Leads + Widgets). */
    public static function isHeroGrid(): bool
    {
        return ! self::isHeroSlider();
    }

    /* The mobile bottom-nav settings only show while the bar itself
     * is displayed; the title size additionally needs titles on. */
    public static function isMobileMenuDisplayed(): bool
    {
        return (bool) get_theme_mod('kk_mobile_menu_display', true);
    }

    public static function isMobileMenuTitlesShown(): bool
    {
        return self::isMobileMenuDisplayed() && (bool) get_theme_mod('kk_mobile_menu_show_titles', true);
    }

    /* The text-brand settings only show while the title text is on;
     * the subtitle additionally needs the slogan on. */
    public static function isLogoTitleShown(): bool
    {
        return (bool) get_theme_mod('kk_logo_show_title', false);
    }

    public static function isLogoSloganShown(): bool
    {
        return self::isLogoTitleShown() && (bool) get_theme_mod('kk_logo_show_slogan', false);
    }

    public static function sanitizeSidebarMode(mixed $value): string
    {
        $allowed = ['logo-only', 'logo-title', 'logo-title-slogan', 'title-only', 'hidden'];

        return in_array($value, $allowed, true) ? (string) $value : 'logo-only';
    }

    public static function sanitizeLogoPosition(mixed $value): string
    {
        $allowed = ['left', 'centered', 'right', 'hide', 'centered-bar'];

        return in_array($value, $allowed, true) ? (string) $value : 'left';
    }

    public static function sanitizeHeroSliderStyle(mixed $value): string
    {
        return in_array($value, ['classic', 'inner'], true) ? (string) $value : 'classic';
    }

    public static function sanitizeHeroLeadTitlePosition(mixed $value): string
    {
        return in_array($value, ['below', 'inside'], true) ? (string) $value : 'below';
    }

    public static function sanitizeHeroBottomBorder(mixed $value): string
    {
        return in_array($value, ['classic', 'no-border', 'double'], true) ? (string) $value : 'classic';
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
        return in_array($value, ['pagination', 'load-more', 'next-prev'], true) ? (string) $value : 'pagination';
    }

    public static function sanitizePaginationNavStyle(mixed $value): string
    {
        return in_array($value, ['text', 'icons'], true) ? (string) $value : 'text';
    }

    public static function sanitizePaginationIcon(mixed $value): string
    {
        $allowed = ['chevrons-left', 'chevron-left', 'arrow-left', 'arrow-left-circle', 'prev', 'chevrons-right', 'chevron-right', 'arrow-right', 'arrow-right-circle', 'next'];

        return in_array($value, $allowed, true) ? (string) $value : 'chevrons-left';
    }

    public static function sanitizeLoadMoreLoadingStyle(mixed $value): string
    {
        return in_array($value, ['icon-text', 'icon', 'text'], true) ? (string) $value : 'icon-text';
    }

    /* The Prev/Next icon pickers only show while the nav style is
     * Icons; the Load More settings only show while the pagination
     * mode is Load More. */
    public static function isPaginationNavIcons(): bool
    {
        return (string) get_theme_mod('kk_pagination_nav_style', 'text') === 'icons';
    }

    public static function isLoadMoreMode(): bool
    {
        return (string) get_theme_mod('kk_pagination_mode', 'pagination') === 'load-more';
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

    public static function sanitizeAgendaLocationPosition(mixed $value): string
    {
        $allowed = ['below-info', 'above-info', 'below-article', 'below-thumbnail', 'before-article'];

        return in_array($value, $allowed, true) ? (string) $value : 'below-info';
    }

    public static function isAgendaLocationEnabled(): bool
    {
        return (bool) get_theme_mod('kk_agenda_location_enabled', true);
    }

    public static function sanitizeHeroMode(mixed $value): string
    {
        // Backward compat: the old grid/slider values map to the
        // matching new modes.
        if ($value === 'grid') {
            return \KoperasiKita\Features\Hero::GRID_LEFT;
        }

        if ($value === 'slider') {
            return \KoperasiKita\Features\Hero::SLIDER_FULL;
        }

        return \KoperasiKita\Features\Hero::sanitize($value);
    }

    public static function sanitizeHeroCardTitlePosition(mixed $value): string
    {
        return in_array($value, ['below', 'above', 'inside'], true) ? (string) $value : 'below';
    }

    public static function sanitizeHeroSource(mixed $value): string
    {
        return in_array($value, ['latest', 'category', 'tag'], true) ? (string) $value : 'latest';
    }

    public static function sanitizeHighlightsItemStyle(mixed $value): string
    {
        return in_array($value, ['thumb-inside', 'thumb-below', 'title-meta'], true) ? (string) $value : 'thumb-below';
    }

    public static function sanitizeHighlightsLayoutStyle(mixed $value): string
    {
        return in_array($value, ['classic', 'no-border', 'box'], true) ? (string) $value : 'classic';
    }

    public static function sanitizeHighlightsDisplay(mixed $value): string
    {
        return in_array($value, ['slider-grid', 'slider-full', 'list'], true) ? (string) $value : 'slider-grid';
    }

    /* The Slider Grid sub-settings (Autoplay + interval) only show
     * while the Highlights Display is Slider Grid. */
    public static function isHighlightsSliderGrid(): bool
    {
        return self::highlightsDisplay() === 'slider-grid';
    }

    /* The Slider Full sub-settings (Autoplay + interval) only show
     * while the Highlights Display is Slider Full. */
    public static function isHighlightsSliderFull(): bool
    {
        return self::highlightsDisplay() === 'slider-full';
    }

    /* The List sub-settings (Columns / Rows / Enable slider) only
     * show while the Highlights Display is List. */
    public static function isHighlightsList(): bool
    {
        return self::highlightsDisplay() === 'list';
    }

    /* The Slider Grid interval only shows while Slider Grid autoplay
     * is on. */
    public static function isHighlightsSliderGridAutoplay(): bool
    {
        return self::isHighlightsSliderGrid() && (bool) get_theme_mod('kk_highlights_slider_grid_autoplay', false);
    }

    /* The Slider Full interval only shows while Slider Full autoplay
     * is on. */
    public static function isHighlightsSliderFullAutoplay(): bool
    {
        return self::isHighlightsSliderFull() && (bool) get_theme_mod('kk_highlights_slider_full_autoplay', false);
    }

    /**
     * The current Highlights Display, mapped from the legacy values
     * (grid-scrollable / list-scrollable / slider-auto /
     * slider-manual) so old installs keep a sensible display.
     */
    public static function highlightsDisplay(): string
    {
        $display = (string) get_theme_mod('kk_highlights_display', 'slider-grid');

        $legacy = [
            'grid-scrollable' => 'slider-grid',
            'slider-auto' => 'slider-grid',
            'slider-manual' => 'slider-grid',
            'list-scrollable' => 'list',
        ];

        if (isset($legacy[$display])) {
            return $legacy[$display];
        }

        return in_array($display, ['slider-grid', 'slider-full', 'list'], true) ? $display : 'slider-grid';
    }

    public static function sanitizeHighlightsLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'slider'], true) ? (string) $value : 'grid';
    }

    public static function sanitizeColorScheme(mixed $value): string
    {
        return in_array($value, ['system', 'light', 'dark'], true) ? (string) $value : 'light';
    }

    public static function sanitizeMenuSide(mixed $value): string
    {
        return in_array($value, ['left', 'right'], true) ? (string) $value : 'left';
    }

    public static function sanitizeWeatherUnit(mixed $value): string
    {
        return in_array($value, ['celsius', 'fahrenheit'], true) ? (string) $value : 'celsius';
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

    public static function sanitizeClockDateFormat(mixed $value): string
    {
        return in_array($value, ['full', 'short', 'numeric'], true) ? (string) $value : 'full';
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
        return in_array($value, ['grid', 'list', 'featured', 'featured-right', 'list-columns', 'newsletter'], true) ? (string) $value : 'grid';
    }

    /* The List (Columns) sub-settings for a category block slot
     * only show while that slot's Display Style is List
     * (Columns). */
    public static function isCategoryListColumns(int $slot): bool
    {
        return \KoperasiKita\Features\Homepage::categoryBlockLayout($slot) === 'list-columns';
    }

    /* Latest-news layout: Grid / List / Newsletter only. Featured
     * is NOT a latest-news layout - it belongs to the category
     * blocks (sanitizeLayout above), so it must never be saved
     * here. */
    public static function sanitizeLatestLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'list', 'newsletter'], true) ? (string) $value : 'grid';
    }

    public static function sanitizeAlignment(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? (string) $value : 'left';
    }

    public static function sanitizeCommentsMode(mixed $value): string
    {
        return in_array($value, ['follow', 'disabled'], true) ? (string) $value : 'follow';
    }

    public static function sanitizeCommentsHide(mixed $value): string
    {
        return in_array($value, ['disabled', 'post', 'all'], true) ? (string) $value : 'post';
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

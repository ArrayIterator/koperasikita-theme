<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Footer;
use KoperasiKita\Features\Performance;
use WP_Customize_Color_Control;
use WP_Customize_Manager;

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
                'newsletter' => esc_html__('Newsletter', 'koperasikita'),
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

        $checkboxes = [
            'kk_single_featured' => ['koperasikita_single', 'Show Featured Image', true],
            'kk_single_author_box' => ['koperasikita_single', 'Show Author Box', true],
            'kk_single_related' => ['koperasikita_single', 'Show Related News', true],
            'kk_single_reading_time' => ['koperasikita_single', 'Show Reading Time', true],
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
        ];

        foreach ($checkboxes as $id => [$section, $label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => $section,
                'type' => 'checkbox',
            ]);
        }

        // Header: breaking-news source, top bar, live clock, sticky header.
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
        $wpCustomize->add_control(new TermCheckboxControl($wpCustomize, 'kk_breaking_category_ids', [
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
        $wpCustomize->add_control(new TermCheckboxControl($wpCustomize, 'kk_breaking_tag_ids', [
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

    public static function sanitizeBreakingSource(mixed $value): string
    {
        return in_array($value, ['latest', 'category', 'tag'], true) ? (string) $value : 'latest';
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

    public static function sanitizeClockTimezone(mixed $value): string
    {
        return in_array($value, ['site', 'device', 'custom'], true) ? (string) $value : 'site';
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

    public static function sanitizeLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'list', 'newsletter'], true) ? (string) $value : 'grid';
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
}

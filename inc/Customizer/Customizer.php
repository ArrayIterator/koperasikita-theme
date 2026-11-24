<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Features\Fonts;
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
            'sanitize_textarea_field',
            'postMessage'
        );
        $wpCustomize->add_control('kk_footer_text', [
            'label' => esc_html__('Footer Text', 'koperasikita'),
            'section' => 'koperasikita_footer',
            'type' => 'textarea',
        ]);

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
            'kk_adv_disable_emoji' => ['koperasikita_advanced', 'Disable Emoji Scripts and Styles', false],
            'kk_adv_disable_embeds' => ['koperasikita_advanced', 'Disable Embeds', false],
            'kk_adv_remove_query_strings' => ['koperasikita_advanced', 'Remove Query Strings from Asset URLs', false],
            'kk_adv_cleanup_head' => ['koperasikita_advanced', 'Remove Extra Tags from <head>', false],
            'kk_breadcrumb_enabled' => ['koperasikita_breadcrumb', 'Show Breadcrumbs', true],
            'kk_breadcrumb_home' => ['koperasikita_breadcrumb', 'Show Breadcrumbs on the Homepage', false],
        ];

        foreach ($checkboxes as $id => [$section, $label, $default]) {
            self::addSetting($wpCustomize, $id, $default, [self::class, 'sanitizeBool'], 'refresh');
            $wpCustomize->add_control($id, [
                'label' => esc_html__($label, 'koperasikita'),
                'section' => $section,
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

    public static function sanitizeLayout(mixed $value): string
    {
        return in_array($value, ['grid', 'list'], true) ? (string) $value : 'grid';
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

<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * The site logo, in four variants: desktop + mobile, each with a
 * light and a dark version.
 *
 * - Logo (desktop light) is the native WordPress custom_logo.
 * - Mobile Logo / Mobile Logo Dark / Dark Logo are theme settings
 *   (Customizer -> Logo), stored as attachment IDs.
 *
 * A variant falls back to its counterpart: the mobile logo falls back
 * to the desktop logo (and vice versa), the dark logo to the mobile
 * dark logo (and vice versa). When a device has no light logo but a
 * dark one, the dark logo shows in both schemes - the masthead never
 * renders empty while any logo is set.
 *
 * The light logo gets the is-inverted class (inverted in dark mode)
 * only when that device has no dark logo AND the "Invert Logo in
 * Dark Mode" setting is on; the dark logo is never inverted.
 * The footer still uses the_custom_logo(), so the get_custom_logo
 * filter (Customizer::filterCustomLogo) keeps handling its invert.
 */
final class Logo
{
    /** Attachment ID for a variant, or 0 when not set. */
    public static function id(string $variant): int
    {
        return match ($variant) {
            'mobile' => (int) get_theme_mod('kk_logo_mobile', 0),
            'mobile-dark' => (int) get_theme_mod('kk_logo_mobile_dark', 0),
            'dark' => (int) get_theme_mod('kk_logo_dark', 0),
            default => (int) get_theme_mod('custom_logo', 0),
        };
    }

    /** Attachment ID of the square logo/icon, or 0 when not set. */
    public static function squareId(): int
    {
        return (int) get_theme_mod('kk_logo_square', 0);
    }

    /** Whether the default logo is ignored (square logo / mark instead). */
    public static function ignoreDefault(): bool
    {
        return (bool) get_theme_mod('kk_logo_ignore_default', false);
    }

    /** The text-brand title: custom text, or the site title. */
    public static function titleText(): string
    {
        $custom = trim((string) get_theme_mod('kk_logo_title_text', ''));

        return '' !== $custom ? $custom : (string) get_bloginfo('name');
    }

    /** The text-brand subtitle: custom text, or the site tagline. */
    public static function subtitleText(): string
    {
        $custom = trim((string) get_theme_mod('kk_logo_subtitle_text', ''));

        return '' !== $custom ? $custom : (string) get_bloginfo('description', 'display');
    }

    /** Whether the masthead shows the title text next to the logo. */
    public static function showTitle(): bool
    {
        return (bool) get_theme_mod('kk_logo_show_title', false);
    }

    /** Whether the masthead shows the slogan under the title. */
    public static function showSlogan(): bool
    {
        return (bool) get_theme_mod('kk_logo_show_slogan', false);
    }

    /** Whether the brand text is hidden on mobile (logo only). */
    public static function hideTextMobile(): bool
    {
        return (bool) get_theme_mod('kk_logo_hide_text_mobile', false);
    }

    /** Header logo position on desktop: left, centered, right, hide. */
    public static function positionDesktop(): string
    {
        $allowed = ['left', 'centered', 'right', 'hide'];
        $position = get_theme_mod('kk_logo_position_desktop', 'left');

        return in_array($position, $allowed, true) ? (string) $position : 'left';
    }

    /** Header logo position on mobile: left, centered, right, hide, centered-bar. */
    public static function positionMobile(): string
    {
        $allowed = ['left', 'centered', 'right', 'hide', 'centered-bar'];
        $position = get_theme_mod('kk_logo_position_mobile', 'left');

        return in_array($position, $allowed, true) ? (string) $position : 'left';
    }

    /** How the drawer (mobile sidebar) brand renders. */
    public static function sidebarMode(): string
    {
        $allowed = ['logo-only', 'logo-title', 'logo-title-slogan', 'title-only', 'hidden'];
        $mode = get_theme_mod('kk_logo_sidebar_mode', 'logo-only');

        return in_array($mode, $allowed, true) ? (string) $mode : 'logo-only';
    }

    /** Extra classes for the masthead brand wrapper. */
    public static function brandClasses(): string
    {
        $classes = '';

        $desktop = self::positionDesktop();

        if ($desktop !== 'left') {
            $classes .= ' kk-brand--pos-desktop-' . $desktop;
        }

        $mobile = self::positionMobile();

        if ($mobile !== 'left') {
            $classes .= ' kk-brand--pos-mobile-' . $mobile;
        }

        if (self::hideTextMobile()) {
            $classes .= ' kk-brand--hide-text-mobile';
        }

        return $classes;
    }

    /**
     * Extra classes for the masthead itself. When the brand is hidden
     * at a breakpoint the whole masthead is hidden there too, so the
     * page is not left with an empty bordered band.
     */
    public static function mastheadClasses(): string
    {
        $classes = '';

        if (self::positionDesktop() === 'hide') {
            $classes .= ' kk-masthead--hide-brand-desktop';
        }

        if (self::positionMobile() === 'hide') {
            $classes .= ' kk-masthead--hide-brand-mobile';
        }

        return $classes;
    }

    /**
     * Register the SVG upload filters. SVGs are only accepted from
     * users who can manage options (the Customizer), and every SVG
     * is screened for active content on upload: a logo SVG must be
     * a picture, never a script. WordPress blocks SVG uploads by
     * default because an unsanitised SVG can run JavaScript; the
     * prefilter below is what makes allowing them safe here.
     */
    public static function register(): void
    {
        add_filter('upload_mimes', [self::class, 'allowSvgUpload']);
        add_filter('wp_handle_upload_prefilter', [self::class, 'rejectUnsafeSvg']);
    }

    /** Allow SVG uploads for administrators (Customizer logos). */
    public static function allowSvgUpload(array $mimes): array
    {
        if (!current_user_can('manage_options')) {
            return $mimes;
        }

        $mimes['svg'] = 'image/svg+xml';

        return $mimes;
    }

    /**
     * Reject an SVG upload that carries active content (scripts,
     * event handlers, javascript: URIs, foreign objects, embeds,
     * forms, XML entities, or server-side processing instructions).
     * Anything else passes through untouched.
     */
    public static function rejectUnsafeSvg(array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        $type = (string) ($file['type'] ?? '');

        if ('image/svg+xml' !== $type && !str_ends_with(strtolower($name), '.svg')) {
            return $file;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $contents = '' !== $tmp && is_readable($tmp) ? file_get_contents($tmp) : false;

        if (false === $contents) {
            $file['error'] = __('This SVG file could not be read.', 'koperasikita');

            return $file;
        }

        $unsafe = [
            '/<script/i',
            '/\son\w+\s*=/i',
            '/javascript\s*:/i',
            '/<foreignObject/i',
            '/<(iframe|object|embed|form|meta|base)\b/i',
            '/<!ENTITY/i',
            '/<\?(?!xml\b)/i',
        ];

        foreach ($unsafe as $pattern) {
            if (1 === preg_match($pattern, $contents)) {
                $file['error'] = __('This SVG file contains active content and cannot be uploaded. Use a plain image SVG.', 'koperasikita');

                return $file;
            }
        }

        return $file;
    }

    /** Desktop light logo (falls back to the mobile logo). */
    public static function desktopId(): int
    {
        return self::id('light') ?: self::id('mobile');
    }

    /** Mobile light logo (falls back to the desktop logo). */
    public static function mobileId(): int
    {
        return self::id('mobile') ?: self::id('light');
    }

    /** Desktop dark logo (falls back to the mobile dark logo). */
    public static function darkId(): int
    {
        return self::id('dark') ?: self::id('mobile-dark');
    }

    /** Mobile dark logo (falls back to the desktop dark logo). */
    public static function mobileDarkId(): int
    {
        return self::id('mobile-dark') ?: self::id('dark');
    }

    /** Whether any logo variant is set. */
    public static function hasLogo(): bool
    {
        // With "Ignore Default Logo" only the square logo counts -
        // the default variants are set but deliberately unused.
        if (self::ignoreDefault()) {
            return 0 !== self::squareId();
        }

        return 0 !== self::desktopId()
            || 0 !== self::mobileId()
            || 0 !== self::darkId()
            || 0 !== self::mobileDarkId();
    }

    /** An <img> for an attachment ID (or '' when 0). */
    public static function img(int $id, string $class): string
    {
        if (0 === $id) {
            return '';
        }

        // SVG has no intrinsic size, so wp_get_attachment_image would
        // emit an <img> without dimensions - build the tag from the
        // URL instead; the CSS sizes the logo.
        if ('image/svg+xml' === get_post_mime_type($id)) {
            $url = wp_get_attachment_url($id);

            if (!is_string($url) || '' === $url) {
                return '';
            }

            return '<img class="' . esc_attr($class) . '" src="' . esc_url($url) . '" alt="' . esc_attr((string) get_bloginfo('name')) . '">';
        }

        $html = wp_get_attachment_image($id, 'full', false, ['class' => $class, 'alt' => (string) get_bloginfo('name')]);

        return is_string($html) ? $html : '';
    }

    /**
     * One device's logo pair: the light image (or the dark image when
     * there is no light one), plus the dark image when set. The
     * wrapper carries kk-logos--has-dark when it has a dark image,
     * which is what the dark-mode CSS keys off.
     */
    private static function pair(int $light, int $dark, string $lightClass, string $darkClass, string $wrapperClass): string
    {
        $invert = (bool) get_theme_mod('kk_logo_invert_dark', false);
        $hasDark = 0 !== $dark && 0 !== $light;

        $classes = $wrapperClass;

        if ($hasDark) {
            $classes .= ' kk-logos--has-dark';
        }

        $html = '<span class="' . esc_attr($classes) . '">';

        if (0 !== $light) {
            $class = $lightClass;

            if (!$hasDark && $invert) {
                $class .= ' is-inverted';
            }

            $html .= self::img($light, $class);
        } elseif (0 !== $dark) {
            // Dark-only: show the dark logo in both schemes.
            $html .= self::img($dark, $lightClass);
        }

        if ($hasDark) {
            $html .= self::img($dark, $darkClass);
        }

        $html .= '</span>';

        return $html;
    }

    /** The text brand (title + optional slogan) for the masthead
     * lockup; '' unless "Show Title Text" is on. */
    private static function textBrand(): string
    {
        if (!self::showTitle()) {
            return '';
        }

        $html = '<span class="kk-brand-text">';
        $html .= '<span class="kk-site-title">' . esc_html(self::titleText()) . '</span>';

        if (self::showSlogan()) {
            $html .= '<span class="kk-site-tagline">' . esc_html(self::subtitleText()) . '</span>';
        }

        $html .= '</span>';

        return $html;
    }

    /** The masthead brand: desktop + mobile logo pairs in one link,
     * plus the text brand when "Show Title Text" is on. With
     * "Ignore Default Logo" the square logo stands alone in both
     * schemes (no dark variant); hasLogo() is false without one,
     * so the caller falls back to the built-in mark. */
    public static function brand(): string
    {
        if (self::ignoreDefault()) {
            $desktop = [self::squareId(), 0];
            $mobile = [self::squareId(), 0];
            $squareClass = 0 !== self::squareId() ? ' kk-logo--square' : '';
        } else {
            $desktop = [self::desktopId(), self::darkId()];
            $mobile = [self::mobileId(), self::mobileDarkId()];
            $squareClass = '';
        }

        $html = '<a class="custom-logo-link" href="' . esc_url(home_url('/')) . '" rel="home">';
        $html .= self::pair($desktop[0], $desktop[1], 'custom-logo kk-logo--light' . $squareClass, 'custom-logo kk-logo--dark', 'kk-brand-logos kk-brand-logos--desktop');
        $html .= self::pair($mobile[0], $mobile[1], 'custom-logo kk-logo--light' . $squareClass, 'custom-logo kk-logo--dark', 'kk-brand-logos kk-brand-logos--mobile');
        $html .= self::textBrand();
        $html .= '</a>';

        return $html;
    }

    /** The drawer header brand, per the "Sidebar Brand" mode: the
     * square logo first when set, else the mobile pair, else the
     * built-in K mark; title and slogan only in the modes that
     * include them. 'hidden' renders nothing. */
    public static function drawerBrand(): string
    {
        $mode = self::sidebarMode();

        if ('hidden' === $mode) {
            return '';
        }

        $showLogo = 'title-only' !== $mode;
        $showTitle = 'logo-only' !== $mode;
        $showSlogan = 'logo-title-slogan' === $mode;

        $html = '<a class="kk-drawer-brand" href="' . esc_url(home_url('/')) . '" rel="home" aria-label="' . esc_attr(get_bloginfo('name')) . '">';

        if ($showLogo) {
            $square = self::squareId();

            if (0 !== $square) {
                $html .= self::img($square, 'kk-drawer-brand-logo kk-logo--square');
            } else {
                $light = self::ignoreDefault() ? 0 : self::mobileId();
                $dark = self::ignoreDefault() ? 0 : self::mobileDarkId();

                if (0 === $light && 0 === $dark) {
                    $html .= '<img class="kk-drawer-brand-logo" src="' . esc_url(get_template_directory_uri() . '/assets/img/kk-v2-gradient-gold.svg') . '" alt="' . esc_attr((string) get_bloginfo('name')) . '" width="32" height="32">';
                } else {
                    $html .= self::pair($light, $dark, 'kk-drawer-brand-logo kk-logo--light', 'kk-drawer-brand-logo kk-logo--dark', 'kk-drawer-brand-logos');
                }
            }
        }

        if ($showTitle) {
            $html .= '<span class="kk-drawer-brand-text">';
            $html .= '<span class="kk-drawer-brand-title">' . esc_html(self::titleText()) . '</span>';

            if ($showSlogan) {
                $html .= '<span class="kk-drawer-brand-slogan">' . esc_html(self::subtitleText()) . '</span>';
            }

            $html .= '</span>';
        }

        $html .= '</a>';

        return $html;
    }
}

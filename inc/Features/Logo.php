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
 * dark one, the dark logo shows in both schemes — the masthead never
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

        $html = wp_get_attachment_image($id, 'full', false, ['class' => $class]);

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

    /** The masthead brand: desktop + mobile logo pairs in one link. */
    public static function brand(): string
    {
        $html = '<a class="custom-logo-link" href="' . esc_url(home_url('/')) . '" rel="home">';
        $html .= self::pair(self::desktopId(), self::darkId(), 'custom-logo kk-logo--light', 'custom-logo kk-logo--dark', 'kk-brand-logos kk-brand-logos--desktop');
        $html .= self::pair(self::mobileId(), self::mobileDarkId(), 'custom-logo kk-logo--light', 'custom-logo kk-logo--dark', 'kk-brand-logos kk-brand-logos--mobile');
        $html .= '</a>';

        return $html;
    }

    /** The drawer header brand: the mobile logo pair, or the K mark. */
    public static function drawerBrand(): string
    {
        $light = self::mobileId();
        $dark = self::mobileDarkId();

        $html = '<a class="kk-drawer-brand" href="' . esc_url(home_url('/')) . '" rel="home" aria-label="' . esc_attr(get_bloginfo('name')) . '">';

        if (0 === $light && 0 === $dark) {
            $html .= '<img class="kk-drawer-brand-logo" src="' . esc_url(get_template_directory_uri() . '/assets/img/logo-k.svg') . '" alt="" width="32" height="32" aria-hidden="true">';
        } else {
            $html .= self::pair($light, $dark, 'kk-drawer-brand-logo kk-logo--light', 'kk-drawer-brand-logo kk-logo--dark', 'kk-drawer-brand-logos');
        }

        $html .= '</a>';

        return $html;
    }
}

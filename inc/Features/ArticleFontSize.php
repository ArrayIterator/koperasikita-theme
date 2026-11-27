<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Article text size: a visitor-facing resize control for full content.
 *
 * The default size comes from the Customizer (`kk_single_font_size`,
 * 8-24px, default 16). A visitor can change it with the A- / A+
 * control rendered before the content; the choice is stored in a
 * cookie (`koperasikita_article_font_size`) and overrides the default
 * on the next load, so the first paint is already the right size.
 *
 * The control is injected by one `the_content` filter, so it appears
 * on every full-content view (single posts, pages and agenda items)
 * without the markup being duplicated into every template. The size
 * itself is applied as the `--article-font-size` custom property on the
 * `<article>` element, which the content styles read.
 */
final class ArticleFontSize
{
    public const MIN = 8;
    public const MAX = 24;
    public const DEFAULT = 16;
    public const COOKIE = 'koperasikita_article_font_size';
    public const SETTING = 'kk_single_font_size';

    public static function register(): void
    {
        // Priority 11: after wpautop (10) and do_shortcode (11,
        // registered earlier), so the control markup is never wrapped
        // in a paragraph or mangled by the content filters.
        add_filter('the_content', [self::class, 'prependControl'], 11);
    }

    public static function clamp(int $size): int
    {
        return min(self::MAX, max(self::MIN, $size));
    }

    /**
     * The admin-chosen default size from the Customizer, clamped.
     */
    public static function defaultSize(): int
    {
        return self::clamp((int) get_theme_mod(self::SETTING, self::DEFAULT));
    }

    /**
     * The size to render: the visitor's cookie when it holds a usable
     * number, otherwise the Customizer default. A non-numeric or
     * missing cookie falls back to the default; a numeric one is
     * clamped into range. In the Customizer preview the cookie is
     * ignored, so changing the setting always shows in the preview.
     */
    public static function currentSize(): int
    {
        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return self::defaultSize();
        }

        $raw = isset($_COOKIE[self::COOKIE]) ? wp_unslash($_COOKIE[self::COOKIE]) : '';

        if (!is_numeric($raw)) {
            return self::defaultSize();
        }

        return self::clamp((int) $raw);
    }

    /**
     * Prepend the resize control before the content. Only on singular
     * post/page/agenda views, in the main loop and main query - never in
     * the admin, feeds, excerpts, cards, widgets, or secondary loops.
     */
    public static function prependControl(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        if (is_admin() || is_feed()) {
            return $content;
        }

        // Excerpts are built by wp_trim_excerpt(), which runs the
        // the_content filters on the raw post content while the
        // get_the_excerpt filter is on the stack. A control prepended
        // here would survive strip_tags() and land at the start of
        // every auto-generated excerpt (cards, widgets, SEO), so skip
        // it: an excerpt is text, never the article itself.
        if (doing_filter('get_the_excerpt')) {
            return $content;
        }

        if (!is_singular(['post', 'page', 'agenda'])) {
            return $content;
        }

        if (!in_the_loop() || !is_main_query()) {
            return $content;
        }

        return self::renderControl(self::currentSize()) . $content;
    }

    /**
     * The resize control. The current and default sizes travel as data
     * attributes so the client script needs no extra request.
     */
    public static function renderControl(int $size): string
    {
        $size = self::clamp($size);
        $default = self::defaultSize();

        $html = '<div class="kk-font-size" role="group" aria-label="' . esc_attr__('Text size', 'koperasikita') . '" data-kk-font-size data-kk-font-size-default="' . esc_attr((string) $default) . '" data-kk-font-size-current="' . esc_attr((string) $size) . '">';
        $html .= '<span class="kk-font-size-label" aria-hidden="true">' . esc_html__('Text size', 'koperasikita') . '</span>';
        $html .= '<button type="button" class="kk-font-size-btn" data-kk-font-size-decrease aria-label="' . esc_attr__('Decrease text size', 'koperasikita') . '">A&minus;</button>';
        $html .= '<output class="kk-font-size-value" data-kk-font-size-value aria-live="polite">' . esc_html(sprintf('%dpx', $size)) . '</output>';
        $html .= '<button type="button" class="kk-font-size-btn" data-kk-font-size-increase aria-label="' . esc_attr__('Increase text size', 'koperasikita') . '">A+</button>';
        $html .= '<button type="button" class="kk-font-size-btn kk-font-size-reset" data-kk-font-size-reset>' . esc_html__('Reset', 'koperasikita') . '</button>';
        $html .= '</div>';

        return $html;
    }
}

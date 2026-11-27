<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Widget;

/**
 * Per-widget outer spacing (Margin Top / Margin Bottom), shared by
 * every Koperasi Kita widget.
 *
 * Widgets pile up when they sit in the same area with no space
 * between them, so each widget carries its own top and bottom
 * margin in pixels, set in the classic form and the block
 * inspector. The margins land on the widget container (the
 * before_widget wrapper) as an inline style, so they work in every
 * widget area and for both classic and block widgets. Both default
 * to 0 - no visual change for an existing widget - except the
 * article-list widgets (Recent, Popular, Related, Editor's Picks),
 * whose bottom margin defaults to 10px.
 */
final class WidgetSpacing
{
    public const MAX = 100;
    public const DEFAULT_BOTTOM_ARTICLE = 10;

    public static function sanitize(mixed $value): int
    {
        return max(0, min(self::MAX, (int) $value));
    }

    /**
     * @param array<string, mixed> $instance
     * @return array{top: int, bottom: int}
     */
    public static function margins(array $instance, int $defaultBottom = 0): array
    {
        return [
            'top' => isset($instance['margin_top']) ? self::sanitize($instance['margin_top']) : 0,
            'bottom' => isset($instance['margin_bottom']) ? self::sanitize($instance['margin_bottom']) : self::sanitize($defaultBottom),
        ];
    }

    /**
     * The margin declarations for an inline style attribute, or an
     * empty string when both margins are 0.
     *
     * @param array{top: int, bottom: int} $margins
     */
    private static function declarations(array $margins): string
    {
        $parts = [];

        if ($margins['top'] !== 0) {
            $parts[] = 'margin-top: ' . $margins['top'] . 'px';
        }

        if ($margins['bottom'] !== 0) {
            $parts[] = 'margin-bottom: ' . $margins['bottom'] . 'px';
        }

        return implode('; ', $parts);
    }

    /**
     * Inject the margins into a widget container opening tag. A tag
     * that already carries a style attribute gets the declarations
     * appended to it; otherwise a style attribute is added. Either
     * margin at 0 leaves the tag untouched.
     *
     * @param array{top: int, bottom: int} $margins
     */
    public static function apply(string $beforeWidget, array $margins): string
    {
        $declarations = self::declarations($margins);

        if ($declarations === '' || $beforeWidget === '') {
            return $beforeWidget;
        }

        if (preg_match('/ style="([^"]*)"/', $beforeWidget, $match)) {
            $existing = rtrim($match[1]);
            $merged = $existing === ''
                ? $declarations
                : $existing . (str_ends_with($existing, ';') ? ' ' : '; ') . $declarations;
            $replaced = preg_replace('/ style="[^"]*"/', ' style="' . $merged . '"', $beforeWidget, 1);

            return is_string($replaced) ? $replaced : $beforeWidget;
        }

        $tagged = preg_replace('/<([a-zA-Z][^>]*?)>/', '<$1 style="' . $declarations . '">', $beforeWidget, 1);

        return is_string($tagged) ? $tagged : $beforeWidget;
    }

    /**
     * The classic form fields: two number inputs in pixels. Values
     * shown are the stored ones, or the widget default when nothing
     * is stored yet.
     *
     * @param array<string, mixed> $instance
     */
    public static function field(WP_Widget $widget, array $instance, int $defaultBottom = 0): void
    {
        $margins = self::margins($instance, $defaultBottom);
        ?>
        <p>
            <label for="<?php echo esc_attr($widget->get_field_id('margin_top')); ?>">
                <?php esc_html_e('Margin top (px)', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="0"
                max="<?php echo esc_attr((string) self::MAX); ?>"
                step="1"
                id="<?php echo esc_attr($widget->get_field_id('margin_top')); ?>"
                name="<?php echo esc_attr($widget->get_field_name('margin_top')); ?>"
                value="<?php echo esc_attr((string) $margins['top']); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($widget->get_field_id('margin_bottom')); ?>">
                <?php esc_html_e('Margin bottom (px)', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="0"
                max="<?php echo esc_attr((string) self::MAX); ?>"
                step="1"
                id="<?php echo esc_attr($widget->get_field_id('margin_bottom')); ?>"
                name="<?php echo esc_attr($widget->get_field_name('margin_bottom')); ?>"
                value="<?php echo esc_attr((string) $margins['bottom']); ?>"
            >
        </p>
        <?php
    }

    /**
     * Validate the margin fields of a widget update. A field missing
     * from the submission keeps its stored value (or the widget
     * default when nothing is stored yet).
     *
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     * @return array{margin_top: int, margin_bottom: int}
     */
    public static function sanitizeInstance(array $new, array $old, int $defaultBottom = 0): array
    {
        return [
            'margin_top' => isset($new['margin_top'])
                ? self::sanitize($new['margin_top'])
                : (isset($old['margin_top']) ? self::sanitize($old['margin_top']) : 0),
            'margin_bottom' => isset($new['margin_bottom'])
                ? self::sanitize($new['margin_bottom'])
                : (isset($old['margin_bottom']) ? self::sanitize($old['margin_bottom']) : self::sanitize($defaultBottom)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Widget;

/**
 * The widget header (title) style, shared by every Koperasi Kita
 * widget that renders a title.
 *
 * Three styles, picked per widget (classic form + block editor
 * InspectorControls):
 * - classic (default): the section-title look — lowercase title with
 *   a rule extending to the right (the "editor's picks" header).
 * - no-border: the same title, without the rule.
 * - hide: no header at all.
 *
 * An empty title hides the header whatever the style says — an empty
 * header is hidden by default.
 */
final class WidgetHeader
{
    public const CLASSIC = 'classic';
    public const NO_BORDER = 'no-border';
    public const HIDE = 'hide';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => self::CLASSIC, 'label' => __('Use classic Header Style', 'koperasikita')],
            ['value' => self::NO_BORDER, 'label' => __('Use No Border Header Style', 'koperasikita')],
            ['value' => self::HIDE, 'label' => __('Hide', 'koperasikita')],
        ];
    }

    public static function sanitize(mixed $value): string
    {
        return in_array($value, [self::CLASSIC, self::NO_BORDER, self::HIDE], true)
            ? (string) $value
            : self::CLASSIC;
    }

    /**
     * @param array<string, mixed> $instance
     */
    public static function style(array $instance): string
    {
        return self::sanitize($instance['header_style'] ?? self::CLASSIC);
    }

    /**
     * The widget container opening tag, with the no-border modifier
     * when the header style is no-border. The container's top rule
     * (content.css: .kk-sidebar .widget / .kk-widget-area .widget)
     * is part of the header style — "no border" must remove it too,
     * or the style setting changes nothing visible (the title rule
     * alone is 1px; the container rule is the thick 2px line).
     *
     * @param array<string, mixed> $args Display arguments.
     */
    public static function beforeWidget(array $args, string $style): string
    {
        $before = (string) ($args['before_widget'] ?? '');

        if ($style === self::NO_BORDER && $before !== '') {
            $before = preg_replace('/class="([^"]*)"/', 'class="$1 kk-widget--no-border"', $before, 1) ?? $before;
        }

        return $before;
    }

    /**
     * Render the widget title (or nothing). An empty title is always
     * hidden; `hide` hides it whatever the title is.
     *
     * @param array<string, mixed> $args Display arguments.
     */
    public static function render(array $args, string $title, string $style): void
    {
        if ($title === '' || $style === self::HIDE) {
            return;
        }

        $before = (string) ($args['before_title'] ?? '');

        if ($style === self::NO_BORDER && $before !== '') {
            // Append the modifier to the title element's class list,
            // whatever the sidebar's before_title class is.
            $before = preg_replace('/class="([^"]*)"/', 'class="$1 kk-widget-title--no-border"', $before, 1) ?? $before;
        }

        echo $before . esc_html($title) . ($args['after_title'] ?? '');
    }

    /**
     * The classic widget form field (a select under the title).
     *
     * @param array<string, mixed> $instance
     */
    public static function field(WP_Widget $widget, array $instance): void
    {
        $style = self::style($instance);
        ?>
        <p>
            <label for="<?php echo esc_attr($widget->get_field_id('header_style')); ?>">
                <?php esc_html_e('Header Style', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($widget->get_field_id('header_style')); ?>"
                name="<?php echo esc_attr($widget->get_field_name('header_style')); ?>"
            >
                <?php foreach (self::options() as $option) : ?>
                    <option value="<?php echo esc_attr($option['value']); ?>" <?php selected($style, $option['value']); ?>>
                        <?php echo esc_html($option['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }
}

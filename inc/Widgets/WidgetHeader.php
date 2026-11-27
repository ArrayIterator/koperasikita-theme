<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Widget;

/**
 * The widget header (title) style, shared by every Koperasi Kita
 * widget that renders a title.
 *
 * Five styles, picked per widget (classic form + block editor
 * InspectorControls). "Classic" is the title with a rule extending
 * to the right (the "editor's picks" header); the variants only
 * differ in where the thick 2px border sits:
 * - classic-top (default): border on top only - the widget
 *   container carries the thick 2px top rule.
 * - classic-top-bottom: border on top AND bottom - the container
 *   carries the top rule, the title carries a 2px bottom border.
 * - classic-bottom: border on bottom only - the container's top
 *   rule is removed, the title carries a 2px bottom border.
 * - no-border ("Borderless"): the same title, without any border
 *   at all - no container rule, no title rule.
 * - hide: no header at all.
 *
 * An empty title hides the header whatever the style says - an empty
 * header is hidden by default.
 */
final class WidgetHeader
{
    public const CLASSIC_TOP = 'classic-top';
    public const CLASSIC_TOP_BOTTOM = 'classic-top-bottom';
    public const CLASSIC_BOTTOM = 'classic-bottom';
    public const NO_BORDER = 'no-border';
    public const HIDE = 'hide';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => self::CLASSIC_TOP, 'label' => __('Classic Top Border', 'koperasikita')],
            ['value' => self::CLASSIC_TOP_BOTTOM, 'label' => __('Classic Top Bottom Border', 'koperasikita')],
            ['value' => self::CLASSIC_BOTTOM, 'label' => __('Classic Bottom Border', 'koperasikita')],
            ['value' => self::NO_BORDER, 'label' => __('Borderless', 'koperasikita')],
            ['value' => self::HIDE, 'label' => __('Hide', 'koperasikita')],
        ];
    }

    public static function sanitize(mixed $value): string
    {
        return in_array($value, [self::CLASSIC_TOP, self::CLASSIC_TOP_BOTTOM, self::CLASSIC_BOTTOM, self::NO_BORDER, self::HIDE], true)
            ? (string) $value
            : self::CLASSIC_TOP;
    }

    /**
     * @param array<string, mixed> $instance
     */
    public static function style(array $instance): string
    {
        return self::sanitize($instance['header_style'] ?? self::CLASSIC_TOP);
    }

    /**
     * The widget container opening tag, with the top-border modifier
     * when the header style carries a top border. The container's top
     * rule belongs to the title style - only classic-top and classic
     * top bottom carry it, so only those two get the class. By
     * default a widget has no top border at all: a widget with no
     * title style (the agenda card, the category summary) passes
     * no-border and gets none, and neither do classic bottom, hide
     * or borderless.
     *
     * @param array<string, mixed> $args Display arguments.
     */
    public static function beforeWidget(array $args, string $style): string
    {
        $before = (string) ($args['before_widget'] ?? '');

        if (in_array($style, [self::CLASSIC_TOP, self::CLASSIC_TOP_BOTTOM], true) && $before !== '') {
            $before = preg_replace('/class="([^"]*)"/', 'class="$1 kk-widget--top-border"', $before, 1) ?? $before;
        }

        return $before;
    }

    /**
     * Render the widget title (or nothing). An empty title is always
     * hidden; `hide` hides it whatever the title is. When $url is
     * given the title is a link to it (the "Link To" of the term
     * list widgets).
     *
     * @param array<string, mixed> $args Display arguments.
     */
    public static function render(array $args, string $title, string $style, string $url = ''): void
    {
        if ($title === '' || $style === self::HIDE) {
            return;
        }

        $before = (string) ($args['before_title'] ?? '');

        if ($before !== '') {
            // Append the modifier to the title element's class list,
            // whatever the sidebar's before_title class is.
            $modifier = match ($style) {
                self::CLASSIC_TOP_BOTTOM => 'kk-widget-title--classic-top-bottom',
                self::CLASSIC_BOTTOM => 'kk-widget-title--classic-bottom',
                self::NO_BORDER => 'kk-widget-title--no-border',
                default => '',
            };

            if ($modifier !== '') {
                $before = preg_replace('/class="([^"]*)"/', 'class="$1 ' . $modifier . '"', $before, 1) ?? $before;
            }
        }

        $inner = $url !== ''
            ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
            : esc_html($title);

        echo $before . $inner . ($args['after_title'] ?? '');
    }

    /**
     * The classic widget form field (a select under the title).
     *
     * $gateOnEmptyTitle is for widgets that render no header at
     * all without a title (the Data Desk panel): with no title
     * every style looks identical, so the select is disabled with
     * a hint instead of pretending to work. Widgets whose title
     * falls back to something else (a term name, a form default)
     * leave it off - their style still applies. A disabled control
     * is not submitted, so the stored style rides along in a
     * hidden input - without it the next save would reset the
     * style to the default.
     *
     * @param array<string, mixed> $instance
     */
    public static function field(WP_Widget $widget, array $instance, bool $gateOnEmptyTitle = false): void
    {
        $style = self::style($instance);
        $hasTitle = trim((string) ($instance['title'] ?? '')) !== '';
        $disabled = $gateOnEmptyTitle && !$hasTitle;
        ?>
        <p>
            <label for="<?php echo esc_attr($widget->get_field_id('header_style')); ?>">
                <?php esc_html_e('Header Style', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($widget->get_field_id('header_style')); ?>"
                name="<?php echo esc_attr($widget->get_field_name('header_style')); ?>"
                <?php echo $disabled ? 'disabled="disabled"' : ''; ?>
            >
                <?php foreach (self::options() as $option) : ?>
                    <option value="<?php echo esc_attr($option['value']); ?>" <?php selected($style, $option['value']); ?>>
                        <?php echo esc_html($option['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($disabled) : ?>
                <span class="description">
                    <?php esc_html_e('Set a title first - the header style only applies to the widget title.', 'koperasikita'); ?>
                </span>
                <input type="hidden" name="<?php echo esc_attr($widget->get_field_name('header_style')); ?>" value="<?php echo esc_attr($style); ?>">
            <?php endif; ?>
        </p>
        <?php
    }
}

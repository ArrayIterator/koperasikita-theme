<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use DateTime;
use DateTimeZone;
use Exception;
use WP_Widget;

/**
 * World clock widget: the current time in each timezone the editor
 * lists, one IANA timezone per line.
 *
 * The time is rendered on the server, so it is correct on first paint
 * with no JavaScript dependency. Unknown timezone names are dropped
 * on save; when nothing valid is left the widget falls back to
 * Asia/Jakarta rather than rendering an empty box.
 */
final class WorldClockWidget extends WP_Widget
{
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public function __construct()
    {
        parent::__construct(
            'kk_world_clock',
            __('Koperasi Kita World Clock', 'koperasikita'),
            [
                'description' => __('Current time in the timezones you list, one per line.', 'koperasikita'),
                'classname' => 'kk-world-clock-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /**
     * @param mixed $value Raw stored value (array from the form, or a
     *     newline-separated string from a block attribute).
     * @return string[]
     */
    public static function cleanTimezones(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value);
        }

        if (!is_array($value)) {
            return [self::DEFAULT_TIMEZONE];
        }

        $valid = timezone_identifiers_list();
        $zones = [];

        foreach ($value as $zone) {
            $zone = trim((string) $zone);

            if ($zone !== '' && in_array($zone, $valid, true) && !in_array($zone, $zones, true)) {
                $zones[] = $zone;
            }
        }

        return $zones !== [] ? $zones : [self::DEFAULT_TIMEZONE];
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $zones = self::cleanTimezones($instance['timezones'] ?? []);
        $showDate = !empty($instance['show_date']);

        echo $args['before_widget'] ?? '';

        if ($title !== '') {
            echo ($args['before_title'] ?? '') . esc_html($title) . ($args['after_title'] ?? '');
        }

        ?>
        <ul class="kk-widget-clock">
            <?php foreach ($zones as $zone) : ?>
                <?php
                try {
                    $now = new DateTime('now', new DateTimeZone($zone));
                } catch (Exception) {
                    continue;
                }
                ?>
                <li class="kk-widget-clock-row">
                    <span class="kk-widget-clock-zone"><?php echo esc_html(str_replace('_', ' ', $zone)); ?></span>
                    <span class="kk-widget-clock-time"><?php echo esc_html($now->format('H:i')); ?></span>
                    <span class="kk-widget-clock-offset"><?php echo esc_html($now->format('P')); ?></span>
                    <?php if ($showDate) : ?>
                        <span class="kk-widget-clock-date"><?php echo esc_html($now->format('D, j M Y')); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('World Clock', 'koperasikita');
        $zones = self::cleanTimezones($instance['timezones'] ?? []);
        $showDate = !isset($instance['show_date']) || !empty($instance['show_date']);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                value="<?php echo esc_attr($title); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('timezones')); ?>">
                <?php esc_html_e('Timezones', 'koperasikita'); ?>
            </label>
            <textarea
                class="widefat"
                rows="4"
                id="<?php echo esc_attr($this->get_field_id('timezones')); ?>"
                name="<?php echo esc_attr($this->get_field_name('timezones')); ?>"
            ><?php echo esc_textarea(implode("\n", $zones)); ?></textarea>
            <span class="description">
                <?php esc_html_e('One IANA timezone per line, e.g. Asia/Jakarta.', 'koperasikita'); ?>
            </span>
        </p>
        <p>
            <input
                class="checkbox"
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_date')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_date')); ?>"
                value="1"
                <?php checked($showDate); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_date')); ?>">
                <?php esc_html_e('Show date', 'koperasikita'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $new New settings.
     * @param array<string, mixed> $old Previous settings.
     * @return array<string, mixed>
     */
    public function update($new, $old): array
    {
        $instance = [];
        $instance['title'] = isset($new['title']) ? sanitize_text_field((string) $new['title']) : '';
        $instance['timezones'] = self::cleanTimezones($new['timezones'] ?? []);
        $instance['show_date'] = !empty($new['show_date']) ? 1 : 0;

        return $instance;
    }
}

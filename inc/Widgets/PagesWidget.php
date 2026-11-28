<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Widget;

/**
 * Pages widget: a simple list of page links, ordered by menu order,
 * date or title. Pages rarely carry a featured image, so there is no
 * thumbnail option - the list is titles only.
 */
final class PagesWidget extends WP_Widget
{
    private const DEFAULT_COUNT = 5;
    private const MAX_COUNT = 10;

    /** @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'menu_order' => __('Menu order', 'koperasikita'),
            'date' => __('Date', 'koperasikita'),
            'title' => __('Title', 'koperasikita'),
        ];
    }

    public function __construct()
    {
        parent::__construct(
            'kk_pages',
            __('KK Pages', 'koperasikita'),
            [
                'description' => __('A list of page links, in menu order, by date or by title.', 'koperasikita'),
                'classname' => 'kk-pages-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $count = isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT;
        $sort = isset($instance['sort_order']) && array_key_exists((string) $instance['sort_order'], self::sortOptions())
            ? (string) $instance['sort_order']
            : 'menu_order';

        $sortColumn = [
            'menu_order' => 'menu_order',
            'date' => 'post_date',
            'title' => 'post_title',
        ][$sort];

        $pages = get_pages([
            'number' => $count,
            'sort_column' => $sortColumn,
            'post_status' => 'publish',
        ]);

        if (!is_array($pages) || $pages === []) {
            if (!is_admin() && !(defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }

            echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance));

            WidgetHeader::render($args, $title, WidgetHeader::style($instance));

            ?>
            <p class="kk-widget-empty"><?php esc_html_e('No pages to show yet.', 'koperasikita'); ?></p>
            <?php

            echo $args['after_widget'] ?? '';

            return;
        }

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance));

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        ?>
        <ul class="kk-widget-pages">
            <?php foreach ($pages as $page) : ?>
                <li class="kk-widget-page">
                    <a class="kk-widget-page-link" href="<?php echo esc_url(get_permalink($page)); ?>">
                        <?php echo esc_html(get_the_title($page)); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Pages', 'koperasikita');
        $count = isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT;
        $sort = isset($instance['sort_order']) && array_key_exists((string) $instance['sort_order'], self::sortOptions())
            ? (string) $instance['sort_order']
            : 'menu_order';
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
        <?php WidgetHeader::field($this, $instance); ?>
        <?php WidgetSpacing::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('count')); ?>">
                <?php esc_html_e('Number of items', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="1"
                max="<?php echo esc_attr((string) self::MAX_COUNT); ?>"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('count')); ?>"
                name="<?php echo esc_attr($this->get_field_name('count')); ?>"
                value="<?php echo esc_attr((string) $count); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('sort_order')); ?>">
                <?php esc_html_e('Sort order', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('sort_order')); ?>"
                name="<?php echo esc_attr($this->get_field_name('sort_order')); ?>"
            >
                <?php foreach (self::sortOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($sort, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        $instance['count'] = isset($new['count'])
            ? max(1, min(self::MAX_COUNT, (int) $new['count']))
            : self::DEFAULT_COUNT;
        $instance['sort_order'] = isset($new['sort_order']) && array_key_exists((string) $new['sort_order'], self::sortOptions())
            ? (string) $new['sort_order']
            : 'menu_order';
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

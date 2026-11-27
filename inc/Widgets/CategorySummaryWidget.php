<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Post;
use WP_Term;
use WP_Widget;

/**
 * Category Summary widget: the latest post from each picked
 * category, side by side in columns - one summary column per
 * category, each with a coloured top border (green, gold, green,
 * ...), the category name, the post title, its excerpt and its
 * time. The section header carries the title on the left and an
 * index link on the right.
 *
 * No WidgetHeader here: the section header is custom (title +
 * index link on one row), like the Agenda widget's dark card.
 * Categories with no posts are skipped; when every picked category
 * is empty the widget outputs nothing on the live site (an empty
 * box helps nobody) and an honest "no posts yet" line in the admin
 * and in REST previews instead. With no categories picked it shows
 * the three categories with the most posts.
 */
final class CategorySummaryWidget extends WP_Widget
{
    public const DEFAULT_COUNT = 1;
    public const MAX_COUNT = 5;
    public const DEFAULT_COLUMNS = 3;

    /** @return array{title: string, category_ids: array<int, int>, count: int, show_excerpt: bool, show_time: bool, index_label: string, index_url: string} */
    private function settings(array $instance): array
    {
        $categoryIds = [];
        if (isset($instance['category_ids']) && is_array($instance['category_ids'])) {
            foreach ($instance['category_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $categoryIds[] = $id;
                }
            }
        }

        return [
            'title' => isset($instance['title']) ? (string) $instance['title'] : '',
            'category_ids' => array_values(array_unique($categoryIds)),
            'count' => isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT,
            'show_excerpt' => isset($instance['show_excerpt']) ? (bool) $instance['show_excerpt'] : true,
            'show_time' => isset($instance['show_time']) ? (bool) $instance['show_time'] : true,
            'index_label' => isset($instance['index_label']) ? (string) $instance['index_label'] : __('Indeks Berita', 'koperasikita'),
            'index_url' => isset($instance['index_url']) ? (string) $instance['index_url'] : '',
        ];
    }

    public function __construct()
    {
        parent::__construct(
            'kk_category_summary',
            __('Koperasi Kita Category Summary', 'koperasikita'),
            [
                'description' => __('The latest post from each picked category, side by side in columns.', 'koperasikita'),
                'classname' => 'kk-category-summary-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $settings = $this->settings($instance);
        $columns = $this->columns($settings['category_ids'], $settings['count']);

        if ($columns === []) {
            if (!is_admin() && !(defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }

            echo WidgetHeader::beforeWidget($args, WidgetHeader::NO_BORDER);
            ?>
            <p class="kk-widget-empty"><?php esc_html_e('No posts to show yet.', 'koperasikita'); ?></p>
            <?php
            echo $args['after_widget'] ?? '';

            return;
        }

        // No-border: the section header carries its own top rule, so
        // the sidebar container's rule must not double it.
        echo WidgetHeader::beforeWidget($args, WidgetHeader::NO_BORDER);

        get_template_part('template-parts/content/category-summary', null, [
            'title' => $settings['title'],
            'index_label' => $settings['index_label'],
            'index_url' => $settings['index_url'],
            'columns' => $columns,
            'show_excerpt' => $settings['show_excerpt'],
            'show_time' => $settings['show_time'],
        ]);

        echo $args['after_widget'] ?? '';
    }

    /**
     * One column per category with posts: the term plus its newest
     * posts, newest first. Empty categories are skipped. With no
     * categories picked, the three categories with the most posts.
     *
     * @param array<int, int> $categoryIds
     * @return array<int, array{term: WP_Term, posts: WP_Post[]}>
     */
    private function columns(array $categoryIds, int $count): array
    {
        $terms = [];

        if ($categoryIds !== []) {
            foreach ($categoryIds as $id) {
                $term = get_term($id, 'category');
                if ($term instanceof WP_Term) {
                    $terms[] = $term;
                }
            }
        } else {
            $top = get_terms([
                'taxonomy' => 'category',
                'hide_empty' => true,
                'orderby' => 'count',
                'order' => 'DESC',
                'number' => self::DEFAULT_COLUMNS,
            ]);
            $terms = is_array($top) ? array_filter($top, static fn($term): bool => $term instanceof WP_Term) : [];
        }

        $columns = [];

        foreach ($terms as $term) {
            $query = new \WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $count,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'tax_query' => [
                    [
                        'taxonomy' => 'category',
                        'field' => 'term_id',
                        'terms' => [$term->term_id],
                    ],
                ],
            ]);

            $posts = is_array($query->posts) ? $query->posts : [];

            if ($posts !== []) {
                $columns[] = ['term' => $term, 'posts' => $posts];
            }
        }

        return $columns;
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        $terms = is_array($terms) ? $terms : [];
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
                value="<?php echo esc_attr($settings['title']); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('category_ids')); ?>">
                <?php esc_html_e('Categories to show', 'koperasikita'); ?>
            </label>
            <?php /* Select2-style picker (see admin.ts): a searchable multi-select, not a checkbox list. */ ?>
            <select
                multiple
                class="widefat"
                data-kk-term-picker
                id="<?php echo esc_attr($this->get_field_id('category_ids')); ?>"
                name="<?php echo esc_attr($this->get_field_name('category_ids')); ?>[]"
                style="min-height: 120px;"
            >
                <?php foreach ($terms as $term) : ?>
                    <?php if (!$term instanceof WP_Term) { continue; } ?>
                    <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected(in_array($term->term_id, $settings['category_ids'], true)); ?>>
                        <?php echo esc_html($term->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">
                <?php esc_html_e('Leave empty to show the three categories with the most posts.', 'koperasikita'); ?>
            </span>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('count')); ?>">
                <?php esc_html_e('Posts per category', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="1"
                max="<?php echo esc_attr((string) self::MAX_COUNT); ?>"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('count')); ?>"
                name="<?php echo esc_attr($this->get_field_name('count')); ?>"
                value="<?php echo esc_attr((string) $settings['count']); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('show_excerpt')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('show_excerpt')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('show_excerpt')); ?>"
                    value="1"
                    <?php checked($settings['show_excerpt']); ?>
                >
                <?php esc_html_e('Show excerpt', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('show_time')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('show_time')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('show_time')); ?>"
                    value="1"
                    <?php checked($settings['show_time']); ?>
                >
                <?php esc_html_e('Show time', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('index_label')); ?>">
                <?php esc_html_e('Index link label', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('index_label')); ?>"
                name="<?php echo esc_attr($this->get_field_name('index_label')); ?>"
                value="<?php echo esc_attr($settings['index_label']); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('index_url')); ?>">
                <?php esc_html_e('Index link URL', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="url"
                id="<?php echo esc_attr($this->get_field_id('index_url')); ?>"
                name="<?php echo esc_attr($this->get_field_name('index_url')); ?>"
                value="<?php echo esc_attr($settings['index_url']); ?>"
                placeholder="https://"
            >
            <span class="description">
                <?php esc_html_e('Leave empty to hide the index link.', 'koperasikita'); ?>
            </span>
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
        $categoryIds = [];
        if (isset($new['category_ids']) && is_array($new['category_ids'])) {
            foreach ($new['category_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $categoryIds[] = $id;
                }
            }
        }
        $instance['category_ids'] = array_values(array_unique($categoryIds));
        $instance['count'] = isset($new['count'])
            ? max(1, min(self::MAX_COUNT, (int) $new['count']))
            : self::DEFAULT_COUNT;
        $instance['show_excerpt'] = !empty($new['show_excerpt']);
        $instance['show_time'] = !empty($new['show_time']);
        $instance['index_label'] = isset($new['index_label']) ? sanitize_text_field((string) $new['index_label']) : __('Indeks Berita', 'koperasikita');
        $instance['index_url'] = isset($new['index_url']) ? esc_url_raw((string) $new['index_url']) : '';

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Post;
use WP_Term;
use WP_Widget;

/**
 * Shared base for the term list content widgets (Category List Content
 * and Tag List Content): posts from one term, in one of five layouts.
 *
 * The widgets differ only in their taxonomy (category vs tag) and
 * labels; everything else is shared:
 *
 * - Title: a custom title, or the term name when empty (the default).
 * - Link To: when on, the title links to the term archive.
 * - Term: one category / tag, picked from a select.
 * - Layout: Featured, Grid, List, Featured Right, List Column.
 * - Count: how many posts to show.
 *
 * When there are no posts the widget outputs nothing on the live site
 * (an empty box helps nobody); in the admin and in REST previews
 * (block editor, Customizer) it renders an honest "no posts yet" line
 * instead, so the editor can see the widget is there but empty. A
 * term that no longer exists is treated the same way.
 */
abstract class AbstractTermListContentWidget extends WP_Widget
{
    protected const DEFAULT_COUNT = 5;
    protected const MAX_COUNT = 10;

    /** The taxonomy this widget lists (category or post_tag). */
    abstract protected function taxonomy(): string;

    /** The form label for the term picker ("Category" / "Tag"). */
    abstract protected function termLabel(): string;

    /**
     * Layouts, in display order: id => label.
     *
     * @return array<string, string>
     */
    public static function layouts(): array
    {
        return [
            'featured' => __('Featured', 'koperasikita'),
            'grid' => __('Grid', 'koperasikita'),
            'list' => __('List', 'koperasikita'),
            'featured-right' => __('Featured Right', 'koperasikita'),
            'list-column' => __('List Column', 'koperasikita'),
        ];
    }

    public static function sanitizeLayout(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return isset(self::layouts()[$value]) ? $value : 'featured';
    }

    /**
     * @return array{title: string, link_to: bool, term_id: int, layout: string, count: int, header_style: string}
     */
    protected function settings(array $instance): array
    {
        return [
            'title' => isset($instance['title']) ? (string) $instance['title'] : '',
            'link_to' => isset($instance['link_to']) ? (bool) $instance['link_to'] : true,
            'term_id' => isset($instance['term_id']) ? max(0, (int) $instance['term_id']) : 0,
            'layout' => self::sanitizeLayout($instance['layout'] ?? 'featured'),
            'count' => isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT,
            'header_style' => WidgetHeader::style($instance),
        ];
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $settings = $this->settings($instance);
        $term = $settings['term_id'] > 0 ? get_term($settings['term_id'], $this->taxonomy()) : null;
        $term = $term instanceof WP_Term ? $term : null;

        $posts = $term !== null ? self::queryPosts($term->term_id, $this->taxonomy(), $settings['count']) : [];

        if ($posts === [] || $term === null) {
            if (!is_admin() && !(defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }

            echo WidgetHeader::beforeWidget($args, $settings['header_style']);

            WidgetHeader::render($args, $settings['title'], $settings['header_style']);

            ?>
            <p class="kk-widget-empty"><?php esc_html_e('No posts to show yet.', 'koperasikita'); ?></p>
            <?php

            echo $args['after_widget'] ?? '';

            return;
        }

        // The title is the custom title, or the term name when empty.
        $title = $settings['title'] !== '' ? $settings['title'] : $term->name;
        $titleUrl = $settings['link_to'] ? (string) get_term_link($term) : '';

        echo WidgetHeader::beforeWidget($args, $settings['header_style']);

        WidgetHeader::render($args, $title, $settings['header_style'], $titleUrl);

        get_template_part('template-parts/content/term-list-content', null, [
            'posts' => $posts,
            'layout' => $settings['layout'],
        ]);

        echo $args['after_widget'] ?? '';
    }

    /**
     * The newest posts in one term, newest first.
     *
     * @return WP_Post[]
     */
    private static function queryPosts(int $termId, string $taxonomy, int $count): array
    {
        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => [$termId],
                ],
            ],
        ]);

        return is_array($query->posts) ? $query->posts : [];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);
        $terms = get_terms([
            'taxonomy' => $this->taxonomy(),
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
            <span class="description">
                <?php esc_html_e('Leave empty to use the term name.', 'koperasikita'); ?>
            </span>
        </p>
        <?php WidgetHeader::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('term_id')); ?>">
                <?php echo esc_html($this->termLabel()); ?>
            </label>
            <?php /* Searchable picker (see admin.ts), not a long plain dropdown. */ ?>
            <select
                class="widefat"
                data-kk-term-picker
                id="<?php echo esc_attr($this->get_field_id('term_id')); ?>"
                name="<?php echo esc_attr($this->get_field_name('term_id')); ?>"
            >
                <option value="0"><?php esc_html_e('— Select —', 'koperasikita'); ?></option>
                <?php foreach ($terms as $term) : ?>
                    <?php if (!$term instanceof WP_Term) { continue; } ?>
                    <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($settings['term_id'], $term->term_id); ?>>
                        <?php echo esc_html($term->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>">
                <?php esc_html_e('Layout', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('layout')); ?>"
                name="<?php echo esc_attr($this->get_field_name('layout')); ?>"
            >
                <?php foreach (self::layouts() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['layout'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('link_to')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('link_to')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('link_to')); ?>"
                    value="1"
                    <?php checked($settings['link_to']); ?>
                >
                <?php esc_html_e('Enable Link To', 'koperasikita'); ?>
            </label>
            <span class="description">
                <?php esc_html_e('Link the title to the term archive.', 'koperasikita'); ?>
            </span>
        </p>
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
                value="<?php echo esc_attr((string) $settings['count']); ?>"
            >
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
        $instance['link_to'] = !empty($new['link_to']);
        $instance['term_id'] = isset($new['term_id']) ? max(0, (int) $new['term_id']) : 0;
        $instance['layout'] = self::sanitizeLayout($new['layout'] ?? 'featured');
        $instance['count'] = isset($new['count'])
            ? max(1, min(self::MAX_COUNT, (int) $new['count']))
            : self::DEFAULT_COUNT;
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);

        return $instance;
    }
}

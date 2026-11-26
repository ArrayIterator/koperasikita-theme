<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Editor's Picks widget: a numbered list of posts (1, 2, 3, 4), like
 * the old hardcoded homepage section — now a proper widget, so the
 * editor places it in any sidebar via Appearance -> Widgets.
 *
 * By terms: pick categories and/or tags to pull posts from. With
 * nothing picked it lists the latest posts from every term.
 *
 * Display options: Show Thumbnail, Show Numeric (the big numbers),
 * Show Date and Show Categories.
 */
final class EditorsPicksWidget extends AbstractStoryWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_editors_picks',
            __('Koperasi Kita Editor’s Picks', 'koperasikita'),
            [
                'description' => __('Numbered list of posts from the categories and tags you pick, newest first.', 'koperasikita'),
                'classname' => 'kk-editors-picks-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @return array<string, mixed> */
    protected function extraSettings(array $instance): array
    {
        return [
            'category_ids' => self::cleanTermIds($instance['category_ids'] ?? []),
            'tag_ids' => self::cleanTermIds($instance['tag_ids'] ?? []),
            'show_numeric' => !isset($instance['show_numeric']) || !empty($instance['show_numeric']),
            'show_date' => !isset($instance['show_date']) || !empty($instance['show_date']),
            'show_categories' => !empty($instance['show_categories']),
        ];
    }

    /**
     * @param mixed $value Raw stored value (array from the form, or a
     *     comma-separated string from a block attribute).
     * @return int[]
     */
    private static function cleanTermIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array{title: string, count: int, layout: string, show_thumbnail: bool, category_ids: int[], tag_ids: int[]} $settings
     * @return \WP_Post[]
     */
    protected function getPosts(array $settings): array
    {
        $args = [
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
        ];

        if ($settings['category_ids'] !== []) {
            $args['category__in'] = $settings['category_ids'];
        }

        if ($settings['tag_ids'] !== []) {
            $args['tag__in'] = $settings['tag_ids'];
        }

        $posts = get_posts($args);

        return is_array($posts) ? $posts : [];
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $settings = $this->settings($instance);
        $posts = $this->getPosts($settings);

        if ($posts === []) {
            if (!is_admin() && !(defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }

            echo $args['before_widget'] ?? '';

            WidgetHeader::render($args, $settings['title'], $settings['header_style']);

            ?>
            <p class="kk-widget-empty"><?php esc_html_e('No posts to show yet.', 'koperasikita'); ?></p>
            <?php

            echo $args['after_widget'] ?? '';

            return;
        }

        echo $args['before_widget'] ?? '';

        WidgetHeader::render($args, $settings['title'], $settings['header_style']);

        ?>
        <ol class="kk-picks-list">
            <?php
            $number = 1;
            foreach ($posts as $post) :
                ?>
                <li class="kk-pick">
                    <?php if ($settings['show_numeric']) : ?>
                        <span class="kk-pick-number" aria-hidden="true"><?php echo esc_html((string) $number); ?></span>
                    <?php endif; ?>
                    <div class="kk-pick-body">
                        <?php if ($settings['show_thumbnail'] && has_post_thumbnail($post)) : ?>
                            <a class="kk-pick-thumb" href="<?php echo esc_url(get_permalink($post)); ?>" tabindex="-1" aria-hidden="true">
                                <?php echo get_the_post_thumbnail($post, 'medium'); ?>
                            </a>
                        <?php endif; ?>
                        <?php
                        $categories = get_the_category($post->ID);
                        if ($settings['show_categories'] && $categories !== []) :
                            ?>
                            <p class="kk-eyebrow"><a href="<?php echo esc_url(get_category_link($categories[0])); ?>"><?php echo esc_html($categories[0]->name); ?></a></p>
                        <?php endif; ?>
                        <h3 class="kk-pick-title"><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3>
                        <?php if ($settings['show_date']) : ?>
                            <p class="kk-meta">
                                <time datetime="<?php echo esc_attr(get_the_date('c', $post)); ?>"><?php echo esc_html(get_the_date('', $post)); ?></time>
                            </p>
                        <?php endif; ?>
                    </div>
                </li>
                <?php
                $number++;
            endforeach;
            ?>
        </ol>
        <?php

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    protected function renderExtraFields(array $instance): void
    {
        $settings = $this->settings($instance);
        $selected_categories = $settings['category_ids'] ?? [];
        $selected_tags = $settings['tag_ids'] ?? [];
        $categories = get_categories(['hide_empty' => false]);
        $tags = get_tags(['hide_empty' => false]);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('category_ids')); ?>">
                <?php esc_html_e('Categories:', 'koperasikita'); ?>
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
                <?php if (is_array($categories)) : ?>
                    <?php foreach ($categories as $category) : ?>
                        <option
                            value="<?php echo esc_attr((string) $category->term_id); ?>"
                            <?php selected(in_array((int) $category->term_id, $selected_categories, true)); ?>
                        ><?php echo esc_html($category->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('tag_ids')); ?>">
                <?php esc_html_e('Tags:', 'koperasikita'); ?>
            </label>
            <select
                multiple
                class="widefat"
                data-kk-term-picker
                id="<?php echo esc_attr($this->get_field_id('tag_ids')); ?>"
                name="<?php echo esc_attr($this->get_field_name('tag_ids')); ?>[]"
                style="min-height: 120px;"
            >
                <?php if (is_array($tags)) : ?>
                    <?php foreach ($tags as $tag) : ?>
                        <option
                            value="<?php echo esc_attr((string) $tag->term_id); ?>"
                            <?php selected(in_array((int) $tag->term_id, $selected_tags, true)); ?>
                        ><?php echo esc_html($tag->name); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </p>
        <p class="description">
            <?php esc_html_e('Leave all unselected to show the latest posts from every category and tag.', 'koperasikita'); ?>
        </p>
        <p>
            <input
                class="checkbox"
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_numeric')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_numeric')); ?>"
                value="1"
                <?php checked($settings['show_numeric']); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_numeric')); ?>">
                <?php esc_html_e('Show Numeric', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <input
                class="checkbox"
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_date')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_date')); ?>"
                value="1"
                <?php checked($settings['show_date']); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_date')); ?>">
                <?php esc_html_e('Show Date', 'koperasikita'); ?>
            </label>
        </p>
        <p>
            <input
                class="checkbox"
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_categories')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_categories')); ?>"
                value="1"
                <?php checked($settings['show_categories']); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_categories')); ?>">
                <?php esc_html_e('Show Categories', 'koperasikita'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $instance
     * @return array<string, mixed>
     */
    protected function sanitizeExtra(array $new, array $instance): array
    {
        $instance['category_ids'] = self::cleanTermIds($new['category_ids'] ?? []);
        $instance['tag_ids'] = self::cleanTermIds($new['tag_ids'] ?? []);
        $instance['show_numeric'] = !empty($new['show_numeric']) ? 1 : 0;
        $instance['show_date'] = !empty($new['show_date']) ? 1 : 0;
        $instance['show_categories'] = !empty($new['show_categories']) ? 1 : 0;

        return $instance;
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);

        if ($settings['title'] === '') {
            $instance['title'] = __('Editor’s Picks', 'koperasikita');
        }

        parent::form($instance);
    }
}

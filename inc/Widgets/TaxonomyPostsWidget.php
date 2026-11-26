<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Category news widget: posts from the categories the editor picks.
 * With no category picked it lists the latest posts from every
 * category, so the widget is useful before any category is chosen.
 */
final class TaxonomyPostsWidget extends AbstractStoryWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_taxonomy_posts',
            __('Koperasi Kita Category News', 'koperasikita'),
            [
                'description' => __('Posts from the categories you pick, newest first.', 'koperasikita'),
                'classname' => 'kk-taxonomy-posts-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @return array<string, mixed> */
    protected function extraSettings(array $instance): array
    {
        return ['category_ids' => self::cleanCategoryIds($instance['category_ids'] ?? [])];
    }

    /**
     * @param mixed $value Raw stored value (array from the form, or a
     *     comma-separated string from a block attribute).
     * @return int[]
     */
    private static function cleanCategoryIds(mixed $value): array
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
     * @param array{title: string, count: int, layout: string, show_thumbnail: bool, category_ids: int[]} $settings
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

        $posts = get_posts($args);

        return is_array($posts) ? $posts : [];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    protected function renderExtraFields(array $instance): void
    {
        $settings = $this->settings($instance);
        $selected = $settings['category_ids'] ?? [];
        $categories = get_categories(['hide_empty' => false]);
        ?>
        <p><?php esc_html_e('Categories:', 'koperasikita'); ?></p>
        <?php if (is_array($categories) && $categories !== []) : ?>
            <?php foreach ($categories as $category) : ?>
                <p>
                    <input
                        type="checkbox"
                        id="<?php echo esc_attr($this->get_field_id('category_ids-' . $category->term_id)); ?>"
                        name="<?php echo esc_attr($this->get_field_name('category_ids')); ?>[]"
                        value="<?php echo esc_attr((string) $category->term_id); ?>"
                        <?php checked(in_array((int) $category->term_id, $selected, true)); ?>
                    >
                    <label for="<?php echo esc_attr($this->get_field_id('category_ids-' . $category->term_id)); ?>">
                        <?php echo esc_html($category->name); ?>
                    </label>
                </p>
            <?php endforeach; ?>
        <?php else : ?>
            <p class="description"><?php esc_html_e('No categories yet.', 'koperasikita'); ?></p>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e('Leave all unchecked to show posts from every category.', 'koperasikita'); ?>
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
        $instance['category_ids'] = self::cleanCategoryIds($new['category_ids'] ?? []);

        return $instance;
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);

        if ($settings['title'] === '') {
            $instance['title'] = __('Category News', 'koperasikita');
        }

        parent::form($instance);
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Editor's Picks widget: posts from the categories and tags you pick,
 * listed like the Popular News widget (compact rows: thumbnail, title,
 * date) - now a proper widget, so the editor places it in any sidebar
 * via Appearance -> Widgets.
 *
 * By terms: pick categories and/or tags to pull posts from. With
 * nothing picked it lists the latest posts from every term.
 *
 * The post order is always shown as its own setting (newest first by
 * default), so the list order is never a hidden default.
 */
final class EditorsPicksWidget extends AbstractStoryWidget
{
    /** @return array<string, string> */
    public static function orderOptions(): array
    {
        return [
            'date_desc' => __('Newest first', 'koperasikita'),
            'date_asc' => __('Oldest first', 'koperasikita'),
        ];
    }

    public function __construct()
    {
        parent::__construct(
            'kk_editors_picks',
            __('KK Post By Terms', 'koperasikita'),
            [
                'description' => __('Posts from the categories and tags you pick, in your chosen order.', 'koperasikita'),
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
            'order' => isset($instance['order']) && array_key_exists((string) $instance['order'], self::orderOptions())
                ? (string) $instance['order']
                : 'date_desc',
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
     * @param array{title: string, count: int, layout: string, show_thumbnail: bool, category_ids: int[], tag_ids: int[], order: string} $settings
     * @return \WP_Post[]
     */
    protected function getPosts(array $settings): array
    {
        $args = [
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => $settings['order'] === 'date_asc' ? 'ASC' : 'DESC',
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

    /** @param array<string, mixed> $instance Widget settings. */
    protected function renderExtraFields(array $instance): void
    {
        $settings = $this->settings($instance);
        $selected_categories = $settings['category_ids'] ?? [];
        $selected_tags = $settings['tag_ids'] ?? [];
        ?>
        <?php Select2TermField::render($this, 'category_ids', __('Categories:', 'koperasikita'), 'category', $selected_categories, true, __('Search terms…', 'koperasikita')); ?>
        <?php Select2TermField::render($this, 'tag_ids', __('Tags:', 'koperasikita'), 'post_tag', $selected_tags, true, __('Search terms…', 'koperasikita')); ?>
        <p class="description">
            <?php esc_html_e('Leave all unselected to show the latest posts from every category and tag.', 'koperasikita'); ?>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('order')); ?>">
                <?php esc_html_e('Order', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('order')); ?>"
                name="<?php echo esc_attr($this->get_field_name('order')); ?>"
            >
                <?php foreach (self::orderOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['order'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        $instance['order'] = isset($new['order']) && array_key_exists((string) $new['order'], self::orderOptions())
            ? (string) $new['order']
            : 'date_desc';

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

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Images\FeaturedImage;
use WP_Post;
use WP_Widget;

/**
 * Shared base for the post-list widgets (Recent, Popular, Related and
 * Editor's Picks).
 *
 * The widgets differ only in which posts they fetch - each subclass
 * implements getPosts() - while the title, count, layout and thumbnail
 * handling is identical. The list is a plain <ul> of post links with an
 * optional thumbnail and date, so it stays readable in any sidebar.
 *
 * When there are no posts at all the widget outputs nothing on the live
 * site (an empty box helps nobody); in the admin and in REST previews
 * (block editor, Customizer) it renders an honest "no posts yet" line
 * instead, so the editor can see the widget is there but empty.
 */
abstract class AbstractStoryWidget extends WP_Widget
{
    protected const DEFAULT_COUNT = 5;
    protected const MAX_COUNT = 10;

    /**
     * Fetch the posts this widget lists.
     *
     * @param array{title: string, count: int, layout: string, show_thumbnail: bool} $settings
     * @return WP_Post[]
     */
    abstract protected function getPosts(array $settings): array;

    /**
     * Extra settings a subclass adds on top of the four shared ones.
     *
     * @return array<string, mixed>
     */
    protected function extraSettings(array $instance): array
    {
        return [];
    }

    /**
     * Extra form fields a subclass renders under the shared ones.
     */
    protected function renderExtraFields(array $instance): void
    {
    }

    /**
     * Validate the extra settings a subclass adds.
     *
     * @param array<string, mixed> $new
     * @param array<string, mixed> $instance
     * @return array<string, mixed>
     */
    protected function sanitizeExtra(array $new, array $instance): array
    {
        return $instance;
    }

    /**
     * @return array{title: string, count: int, layout: string, show_thumbnail: bool, header_style: string}
     */
    protected function settings(array $instance): array
    {
        $settings = [
            'title' => isset($instance['title']) ? (string) $instance['title'] : '',
            'count' => isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT,
            'layout' => isset($instance['layout']) && in_array($instance['layout'], ['list', 'grid'], true) ? (string) $instance['layout'] : 'list',
            'show_thumbnail' => !empty($instance['show_thumbnail']),
            'header_style' => WidgetHeader::style($instance),
        ];

        return array_merge($settings, $this->extraSettings($instance));
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $settings = $this->settings($instance);
        $posts = $this->getPosts($settings);

        if ($posts === []) {
            // Live site: an empty widget box helps nobody, so output
            // nothing. Admin and REST previews (block editor, Customizer)
            // get the honest empty line instead.
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

        echo WidgetHeader::beforeWidget($args, $settings['header_style']);

        WidgetHeader::render($args, $settings['title'], $settings['header_style']);

        ?>
        <ul class="kk-widget-stories kk-widget-stories--<?php echo esc_attr($settings['layout']); ?>">
            <?php foreach ($posts as $post) : ?>
                <li class="kk-widget-story">
                    <a class="kk-widget-story-link" href="<?php echo esc_url(get_permalink($post)); ?>">
                        <?php if ($settings['show_thumbnail']) : ?>
                            <span class="kk-widget-story-thumb">
                                <?php if (has_post_thumbnail($post)) : ?>
                                    <?php echo get_the_post_thumbnail($post, 'thumbnail'); ?>
                                <?php else : ?>
                                    <img src="<?php echo esc_url(FeaturedImage::defaultThumbnailUrl()); ?>" alt="" loading="lazy">
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <span class="kk-widget-story-text">
                            <span class="kk-widget-story-title"><?php echo esc_html(get_the_title($post)); ?></span>
                            <time class="kk-widget-story-date" datetime="<?php echo esc_attr(get_the_date('c', $post)); ?>">
                                <?php echo esc_html(get_the_date('', $post)); ?>
                            </time>
                        </span>
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
        $settings = $this->settings($instance);
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
        <?php WidgetHeader::field($this, $instance); ?>
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
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>">
                <?php esc_html_e('Layout', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('layout')); ?>"
                name="<?php echo esc_attr($this->get_field_name('layout')); ?>"
            >
                <option value="list" <?php selected($settings['layout'], 'list'); ?>>
                    <?php esc_html_e('List', 'koperasikita'); ?>
                </option>
                <option value="grid" <?php selected($settings['layout'], 'grid'); ?>>
                    <?php esc_html_e('Grid', 'koperasikita'); ?>
                </option>
            </select>
        </p>
        <p>
            <input
                class="checkbox"
                type="checkbox"
                id="<?php echo esc_attr($this->get_field_id('show_thumbnail')); ?>"
                name="<?php echo esc_attr($this->get_field_name('show_thumbnail')); ?>"
                value="1"
                <?php checked($settings['show_thumbnail']); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_thumbnail')); ?>">
                <?php esc_html_e('Show thumbnails', 'koperasikita'); ?>
            </label>
        </p>
        <?php

        $this->renderExtraFields($instance);
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
        $instance['layout'] = isset($new['layout']) && in_array($new['layout'], ['list', 'grid'], true)
            ? (string) $new['layout']
            : 'list';
        $instance['show_thumbnail'] = !empty($new['show_thumbnail']) ? 1 : 0;
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);

        return $this->sanitizeExtra($new, $instance);
    }
}

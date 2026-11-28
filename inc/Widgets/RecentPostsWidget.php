<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Recent news widget: the latest published posts, newest first.
 */
final class RecentPostsWidget extends AbstractStoryWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_recent_posts',
            __('KK Recent News', 'koperasikita'),
            [
                'description' => __('The latest published posts, newest first.', 'koperasikita'),
                'classname' => 'kk-recent-posts-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /**
     * @param array{title: string, count: int, layout: string, show_thumbnail: bool} $settings
     * @return \WP_Post[]
     */
    protected function getPosts(array $settings): array
    {
        $posts = get_posts([
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
        ]);

        return is_array($posts) ? $posts : [];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);

        if ($settings['title'] === '') {
            $instance['title'] = __('Latest News', 'koperasikita');
        }

        parent::form($instance);
    }
}

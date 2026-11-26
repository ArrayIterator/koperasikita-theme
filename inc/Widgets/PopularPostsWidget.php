<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Popular news widget (Terpopuler): posts ranked by comment count, most
 * discussed first.
 *
 * A comment-count ranking is meaningless when nothing has been discussed
 * yet, so when no post in the result has any comment the widget falls
 * back to the most recent posts instead of showing a list ordered by
 * zeroes.
 */
final class PopularPostsWidget extends AbstractStoryWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_popular_posts',
            __('Koperasi Kita Popular News', 'koperasikita'),
            [
                'description' => __('Most discussed posts, ranked by comment count.', 'koperasikita'),
                'classname' => 'kk-popular-posts-widget',
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
            'orderby' => 'comment_count',
            'order' => 'DESC',
        ]);

        if (!is_array($posts)) {
            $posts = [];
        }

        foreach ($posts as $post) {
            if ((int) $post->comment_count > 0) {
                return $posts;
            }
        }

        // Nothing has been discussed yet: the ranking is all zeroes, so
        // show the most recent posts instead.
        $recent = get_posts([
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
        ]);

        return is_array($recent) ? $recent : [];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);

        if ($settings['title'] === '') {
            $instance['title'] = __('Popular News', 'koperasikita');
        }

        parent::form($instance);
    }
}

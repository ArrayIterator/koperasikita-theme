<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Related news widget: on a single post, posts that share its categories
 * or tags (the current post excluded); anywhere else it lists the most
 * recent posts, so the widget never renders an empty box on archives or
 * pages.
 */
final class RelatedPostsWidget extends AbstractStoryWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_related_posts',
            __('Koperasi Kita Related News', 'koperasikita'),
            [
                'description' => __('Posts related to the one being read, or the latest posts elsewhere.', 'koperasikita'),
                'classname' => 'kk-related-posts-widget',
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
        $recent = [
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
        ];

        if (!is_single()) {
            $posts = get_posts($recent);

            return is_array($posts) ? $posts : [];
        }

        $postId = (int) get_queried_object_id();

        if ($postId <= 0) {
            $posts = get_posts($recent);

            return is_array($posts) ? $posts : [];
        }

        $categories = wp_get_post_categories($postId);
        $tags = wp_get_post_tags($postId, ['fields' => 'ids']);

        $taxQuery = ['relation' => 'OR'];

        if (is_array($categories) && $categories !== []) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => array_map('intval', $categories),
            ];
        }

        if (is_array($tags) && $tags !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => array_map('intval', $tags),
            ];
        }

        if (count($taxQuery) === 1) {
            // The post has neither categories nor tags: recent posts,
            // still excluding the one being read.
            $recent['post__not_in'] = [$postId];
            $posts = get_posts($recent);

            return is_array($posts) ? $posts : [];
        }

        $posts = get_posts([
            'numberposts' => $settings['count'],
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
            'post__not_in' => [$postId],
            'tax_query' => $taxQuery,
        ]);

        return is_array($posts) ? $posts : [];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $settings = $this->settings($instance);

        if ($settings['title'] === '') {
            $instance['title'] = __('Related News', 'koperasikita');
        }

        parent::form($instance);
    }
}

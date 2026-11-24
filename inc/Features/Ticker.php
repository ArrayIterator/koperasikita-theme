<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * News ticker: content width, bouncing fade rotation.
 *
 * The headline source is Customizer-controlled: the latest posts, or
 * posts from selected categories / tags. A source with no usable terms
 * falls back to the latest posts, so the ticker is never empty while
 * published posts exist. The rotation speed (seconds between
 * headlines) is read by main.ts from the list's data-speed attribute.
 */
final class Ticker
{
    public static function register(): void
    {
        // The ticker renders from template-parts/header/ticker.php;
        // nothing to hook.
    }

    public static function source(): string
    {
        $source = (string) get_theme_mod('kk_breaking_source', 'latest');

        return in_array($source, ['latest', 'category', 'tag'], true) ? $source : 'latest';
    }

    public static function speed(): int
    {
        return min(120, max(2, absint((string) get_theme_mod('kk_breaking_speed', 5))));
    }

    public static function count(): int
    {
        return min(10, max(1, absint((string) get_theme_mod('kk_breaking_count', 5))));
    }

    /**
     * @return array<int, \WP_Post>
     */
    public static function posts(): array
    {
        $count = self::count();
        $source = self::source();

        $query = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => $count,
            'ignore_sticky_posts' => true,
        ];

        if ($source === 'category') {
            $ids = self::termIds('kk_breaking_category_ids');

            if ($ids !== []) {
                $query['category__in'] = $ids;
            }
        } elseif ($source === 'tag') {
            $ids = self::termIds('kk_breaking_tag_ids');

            if ($ids !== []) {
                $query['tag__in'] = $ids;
            }
        }

        $posts = get_posts($query);

        // A filtered source with no matches (or no terms picked) falls
        // back to the latest posts instead of an empty ticker.
        if ($posts === [] && $source !== 'latest') {
            unset($query['category__in'], $query['tag__in']);
            $posts = get_posts($query);
        }

        $unique = [];

        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $unique[$post->ID] = $post;
            }
        }

        return array_values($unique);
    }

    /**
     * @return array<int>
     */
    private static function termIds(string $setting): array
    {
        $ids = (array) get_theme_mod($setting, []);
        $ids = array_map(static fn (mixed $id): int => (int) $id, $ids);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }
}

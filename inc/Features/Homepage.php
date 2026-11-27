<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Homepage sections that the front-page template renders.
 *
 * Category blocks: three slots, each either a category the editor
 * picked in the Customizer or automatic (the most-posted category
 * not already used by an earlier slot). A disabled slot is skipped
 * outright; a picked category that no longer exists falls back to
 * automatic for that slot, so the front page never shows a hole.
 * Each slot carries its own post count and its own display style
 * (Grid / List / Featured), so the blocks are independent of each
 * other.
 */
final class Homepage
{
    /**
     * @return list<array{term: \WP_Term, slot: int}>
     */
    public static function categoryBlocks(): array
    {
        $chosen = [];
        $automatic = null;
        $blocks = [];

        for ($slot = 1; $slot <= 3; $slot++) {
            if (!(bool) get_theme_mod('kk_homepage_category_' . $slot . '_enabled', true)) {
                continue;
            }

            $term_id = (int) get_theme_mod('kk_homepage_category_' . $slot, 0);
            $term = $term_id > 0 ? get_term($term_id, 'category') : null;

            if ($term instanceof \WP_Term && !isset($chosen[$term->term_id])) {
                $chosen[$term->term_id] = $term;
                $blocks[] = ['term' => $term, 'slot' => $slot];

                continue;
            }

            // Automatic: the most-posted category not already used -
            // also the fallback when a slot's pick is invalid or was
            // already picked by an earlier slot.
            if ($automatic === null) {
                $automatic = get_categories([
                    'orderby' => 'count',
                    'order' => 'DESC',
                    'number' => 10,
                    'hide_empty' => true,
                ]);
            }

            foreach ($automatic as $candidate) {
                if (!isset($chosen[$candidate->term_id])) {
                    $chosen[$candidate->term_id] = $candidate;
                    $blocks[] = ['term' => $candidate, 'slot' => $slot];
                    break;
                }
            }
        }

        return $blocks;
    }

    public static function categoryBlockCount(int $slot): int
    {
        // Per-slot count, falling back to the old shared setting so a
        // site that configured it before the per-slot counts existed
        // keeps its number instead of silently resetting to 4.
        $count = get_theme_mod('kk_homepage_category_' . $slot . '_count', false);

        if ($count === false) {
            $count = get_theme_mod('kk_homepage_category_count', 4);
        }

        return min(8, max(1, (int) $count));
    }

    public static function categoryBlockLayout(int $slot): string
    {
        $layout = (string) get_theme_mod('kk_homepage_category_' . $slot . '_layout', 'grid');

        return in_array($layout, ['grid', 'list', 'featured', 'featured-right', 'list-columns'], true) ? $layout : 'grid';
    }

    /**
     * The List (Columns) settings for a category block slot:
     * [columns (1-4), rows per column (1-10), enable slider].
     *
     * @return array{columns: int, rows: int, enable_slider: bool}
     */
    public static function categoryBlockListSettings(int $slot): array
    {
        $columns = (int) get_theme_mod('kk_homepage_category_' . $slot . '_list_columns', 2);
        $rows = (int) get_theme_mod('kk_homepage_category_' . $slot . '_list_rows', 3);

        return [
            'columns' => max(1, min(4, $columns)),
            'rows' => max(1, min(10, $rows)),
            'enable_slider' => (bool) get_theme_mod('kk_homepage_category_' . $slot . '_list_enable_slider', false),
        ];
    }

    /**
     * Highlights ("Sorotan") posts: the editor-picked categories
     * and/or tags, OR-related (a post matches when it is in any
     * picked category OR has any picked tag). Empty filters fall back
     * to the latest posts, so the section never shows a hole.
     *
     * @return \WP_Post[]
     */
    public static function highlightsPosts(): array
    {
        $count = min(9, max(1, (int) get_theme_mod('kk_highlights_count', 3)));
        $categoryIds = array_values(array_filter(array_map('absint', (array) get_theme_mod('kk_highlights_category_ids', []))));
        $tagIds = array_values(array_filter(array_map('absint', (array) get_theme_mod('kk_highlights_tag_ids', []))));

        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'ignore_sticky_posts' => true,
        ];

        if ($categoryIds !== [] && $tagIds !== []) {
            $args['tax_query'] = [
                'relation' => 'OR',
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $categoryIds,
                    'operator' => 'IN',
                ],
                [
                    'taxonomy' => 'post_tag',
                    'field' => 'term_id',
                    'terms' => $tagIds,
                    'operator' => 'IN',
                ],
            ];
        } elseif ($categoryIds !== []) {
            $args['category__in'] = $categoryIds;
        } elseif ($tagIds !== []) {
            $args['tag__in'] = $tagIds;
        }

        $query = new \WP_Query($args);

        return $query->posts;
    }

    /**
     * Hero posts: the latest posts, or the picked categories when the
     * hero source is Category (empty pick falls back to latest).
     *
     * @return \WP_Post[]
     */
    public static function heroPosts(): array
    {
        $count = min(20, max(1, (int) get_theme_mod('kk_hero_count', 5)));
        $source = (string) get_theme_mod('kk_hero_source', 'latest');
        $source = in_array($source, ['latest', 'category', 'tag'], true) ? $source : 'latest';

        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'ignore_sticky_posts' => true,
        ];

        if ($source === 'category') {
            $categoryIds = array_values(array_filter(array_map('absint', (array) get_theme_mod('kk_hero_category_ids', []))));

            if ($categoryIds !== []) {
                $args['category__in'] = $categoryIds;
            }
        } elseif ($source === 'tag') {
            $tagIds = array_values(array_filter(array_map('absint', (array) get_theme_mod('kk_hero_tag_ids', []))));

            if ($tagIds !== []) {
                $args['tag__in'] = $tagIds;
            }
        }

        $query = new \WP_Query($args);

        // A category/tag with fewer posts than the count still
        // renders what it has; only an empty result falls back to
        // latest. An empty tag/category list is latest already.
        if ($query->posts === [] && $source !== 'latest') {
            unset($args['category__in'], $args['tag__in']);
            $query = new \WP_Query($args);
        }

        return $query->posts;
    }
}

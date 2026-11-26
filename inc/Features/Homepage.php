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

            // Automatic: the most-posted category not already used —
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

        return in_array($layout, ['grid', 'list', 'featured'], true) ? $layout : 'grid';
    }
}

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
 */
final class Homepage
{
    /**
     * @return array<int, \WP_Term>
     */
    public static function categoryBlocks(): array
    {
        $chosen = [];
        $automatic = null;

        for ($slot = 1; $slot <= 3; $slot++) {
            if (!(bool) get_theme_mod('kk_homepage_category_' . $slot . '_enabled', true)) {
                continue;
            }

            $term_id = (int) get_theme_mod('kk_homepage_category_' . $slot, 0);
            $term = $term_id > 0 ? get_term($term_id, 'category') : null;

            if ($term instanceof \WP_Term && !isset($chosen[$term->term_id])) {
                $chosen[$term->term_id] = $term;

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
                    break;
                }
            }
        }

        return array_values($chosen);
    }

    public static function categoryBlockCount(): int
    {
        return min(8, max(1, (int) get_theme_mod('kk_homepage_category_count', 4)));
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Response;
use WP_Error;

/**
 * AJAX term + post search for the Customizer pickers.
 *
 * The term/post pickers (TermSelectControl, TermMultiSelectControl)
 * are Select2-style with AJAX mode: they never render the full term
 * or post list into the page. Instead they ask this endpoint for the
 * matches as the editor types, so a site with hundreds of categories,
 * tags or posts stays light on both the server (one small query per
 * keystroke, capped at 20 rows) and the client (only the matches are
 * ever in the DOM). Both endpoints require `edit_theme_options` —
 * the same capability the Customizer itself needs — and answer with
 * the shared Response envelope through Ajax::add().
 */
final class TermSearch
{
    public static function register(): void
    {
        Ajax::add('kk_search_terms', [self::class, 'ajaxSearchTerms'], false, 'edit_theme_options');
        Ajax::add('kk_search_posts', [self::class, 'ajaxSearchPosts'], false, 'edit_theme_options');
    }

    /**
     * @return array{results: list<array{id: int, text: string}>}
     */
    public static function ajaxSearchTerms(): array
    {
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';

        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            throw new ApiException(Response::ERR_INVALID_REQUEST, __('Unknown taxonomy.', 'koperasikita'), 400);
        }

        $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'search' => $query,
            'number' => 20,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if ($terms instanceof WP_Error) {
            throw new ApiException(Response::ERR_INTERNAL, __('Could not load the terms.', 'koperasikita'), 500);
        }

        $results = [];

        foreach ($terms as $term) {
            $results[] = [
                'id' => (int) $term->term_id,
                /* The count tells the editor which terms actually have posts. */
                'text' => $term->name . ' (' . (int) $term->count . ')',
            ];
        }

        return ['results' => $results];
    }

    /**
     * @return array{results: list<array{id: int, text: string}>}
     */
    public static function ajaxSearchPosts(): array
    {
        $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $results = [];

        foreach ($posts as $post) {
            $results[] = [
                'id' => (int) $post->ID,
                'text' => get_the_title($post),
            ];
        }

        return ['results' => $results];
    }
}

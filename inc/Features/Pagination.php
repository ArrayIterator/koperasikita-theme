<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Api;

/**
 * Archive listings: the card layout, the pagination mode, and the
 * data the load-more loader needs.
 *
 * The loader never trusts the browser's idea of the query. The
 * template renders the current main query's allowlisted vars as a
 * data attribute; the REST /posts endpoint re-runs exactly those
 * vars (plus the requested page) and returns the same card template
 * the server rendered for page one, so page two looks identical.
 */
final class Pagination
{
    /**
     * @return array<string>
     */
    public static function modes(): array
    {
        return ['pagination', 'load-more', 'next-prev'];
    }

    public static function mode(): string
    {
        $mode = (string) get_theme_mod('kk_pagination_mode', 'pagination');

        return in_array($mode, self::modes(), true) ? $mode : 'pagination';
    }

    public static function archiveLayout(): string
    {
        $layout = (string) get_theme_mod('kk_archive_layout', 'grid');

        return in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
    }

    /**
     * The current main query, reduced to the vars the /posts endpoint
     * accepts. Anything else (ordering, meta queries, post__in and
     * friends) is dropped — the endpoint rebuilds the rest itself.
     *
     * @return array<string, mixed>
     */
    public static function queryVars(?\WP_Query $query = null): array
    {
        $query = $query ?? $GLOBALS['wp_query'] ?? null;

        if (!$query instanceof \WP_Query) {
            return [];
        }

        $vars = $query->query_vars;
        $allowed = [];

        foreach (['cat' => 'int', 'tag_id' => 'int', 'author' => 'int', 'year' => 'int', 'monthnum' => 'int', 'day' => 'int'] as $key => $type) {
            if (isset($vars[$key]) && (int) $vars[$key] !== 0) {
                $allowed[$key] = (int) $vars[$key];
            }
        }

        if (isset($vars['s']) && is_string($vars['s']) && $vars['s'] !== '') {
            $allowed['s'] = sanitize_text_field($vars['s']);
        }

        if (isset($vars['post_type'])) {
            $type = $vars['post_type'];

            // 'any' is what a search query carries: every public type.
            // The endpoint renders news cards for it, exactly like the
            // search template does for page one.
            if (is_string($type) && in_array($type, ['post', 'agenda', 'any'], true)) {
                $allowed['post_type'] = $type;
            } elseif (is_array($type)) {
                $types = array_values(array_intersect($type, ['post', 'agenda']));

                if ($types !== []) {
                    $allowed['post_type'] = $types;
                }
            }
        }

        return $allowed;
    }

    public static function maxPages(?\WP_Query $query = null): int
    {
        $query = $query ?? $GLOBALS['wp_query'] ?? null;

        if (!$query instanceof \WP_Query) {
            return 1;
        }

        return max(1, (int) $query->max_num_pages);
    }

    public static function perPage(?\WP_Query $query = null): int
    {
        $query = $query ?? $GLOBALS['wp_query'] ?? null;

        if ($query instanceof \WP_Query) {
            $per_page = (int) $query->get('posts_per_page');

            if ($per_page > 0) {
                return min(20, $per_page);
            }
        }

        return (int) get_option('posts_per_page', 10);
    }

    /**
     * The load-more button plus the status region. The loader script
     * reads everything from these data attributes; there is no inline
     * script.
     */
    public static function renderLoader(): void
    {
        $mode = self::mode();

        if ($mode === 'pagination') {
            return;
        }

        $max_pages = self::maxPages();

        if ($max_pages < 2) {
            return;
        }

        $query = self::queryVars();
        $query['post_type'] = $query['post_type'] ?? 'post';
        $endpoint = esc_url_raw(rest_url(Api::REST_NAMESPACE . '/posts'));
        ?>
        <div
            class="kk-pagination kk-pagination-loader"
            data-kk-pagination
            data-mode="<?php echo esc_attr($mode); ?>"
            data-endpoint="<?php echo esc_attr($endpoint); ?>"
            data-query="<?php echo esc_attr((string) wp_json_encode($query)); ?>"
            data-page="1"
            data-max-pages="<?php echo esc_attr((string) $max_pages); ?>"
            data-per-page="<?php echo esc_attr((string) self::perPage()); ?>"
        >
            <button type="button" class="kk-load-more" data-kk-load-more>
                <?php esc_html_e('Load more', 'koperasikita'); ?>
            </button>
            <p class="kk-pagination-status" data-kk-pagination-status aria-live="polite"></p>
            <p class="kk-pagination-end" data-kk-pagination-end hidden><?php esc_html_e('No more posts.', 'koperasikita'); ?></p>
        </div>
        <?php
    }
}

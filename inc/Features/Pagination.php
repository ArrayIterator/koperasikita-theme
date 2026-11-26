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

    /**
     * How the Previous / Next links render: 'text' (words) or
     * 'icons' (double chevrons « »).
     */
    public static function navStyle(): string
    {
        $style = (string) get_theme_mod('kk_pagination_nav_style', 'text');

        return in_array($style, ['text', 'icons'], true) ? $style : 'text';
    }

    /**
     * Previous-link label for paginate_links / the_posts_pagination.
     * Icons mode returns the chosen icon SVG (aria-hidden) plus a
     * screen-reader word, so the link stays understandable without
     * visible text.
     */
    public static function prevText(): string
    {
        if (self::navStyle() === 'icons') {
            return '<span aria-hidden="true">' . \KoperasiKita\Icons\Icon::svg(self::prevIcon(), 18) . '</span><span class="screen-reader-text">' . __('Newer', 'koperasikita') . '</span>';
        }

        return __('&larr; Newer', 'koperasikita');
    }

    public static function nextText(): string
    {
        if (self::navStyle() === 'icons') {
            return '<span aria-hidden="true">' . \KoperasiKita\Icons\Icon::svg(self::nextIcon(), 18) . '</span><span class="screen-reader-text">' . __('Older', 'koperasikita') . '</span>';
        }

        return __('Older &rarr;', 'koperasikita');
    }

    /** Icon name for the Prev link (Customizer pick, default «). */
    public static function prevIcon(): string
    {
        $icon = (string) get_theme_mod('kk_pagination_prev_icon', 'chevrons-left');

        return \KoperasiKita\Icons\Icon::exists($icon) ? $icon : 'chevrons-left';
    }

    /** Icon name for the Next link (Customizer pick, default »). */
    public static function nextIcon(): string
    {
        $icon = (string) get_theme_mod('kk_pagination_next_icon', 'chevrons-right');

        return \KoperasiKita\Icons\Icon::exists($icon) ? $icon : 'chevrons-right';
    }

    /** Load More button text (Customizer, default "Load more"). */
    public static function loadMoreText(): string
    {
        $text = (string) get_theme_mod('kk_load_more_text', 'Load more');

        return $text !== '' ? $text : __('Load more', 'koperasikita');
    }

    /** What the Load More button shows while loading. */
    public static function loadingStyle(): string
    {
        $style = (string) get_theme_mod('kk_load_more_loading_style', 'icon-text');

        return in_array($style, ['icon-text', 'icon', 'text'], true) ? $style : 'icon-text';
    }

    /**
     * How many pages Load More may fetch before the normal numbered
     * navigation takes over. 0 means unlimited.
     */
    public static function loadMoreMaxPages(): int
    {
        return max(0, min(10, (int) get_theme_mod('kk_load_more_max_pages', 3)));
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
     * script. Pass a query to load more of that query (the homepage
     * latest news); omit it to load more of the main query.
     */
    public static function renderLoader(?\WP_Query $query = null): void
    {
        $mode = self::mode();

        if ($mode === 'pagination') {
            return;
        }

        $max_pages = self::maxPages($query);

        if ($max_pages < 2) {
            return;
        }

        $queryVars = self::queryVars($query);
        $queryVars['post_type'] = $queryVars['post_type'] ?? 'post';
        $endpoint = esc_url_raw(rest_url(Api::REST_NAMESPACE . '/posts'));
        $load_more_max = self::loadMoreMaxPages();
        // The cap only matters when it stops before the last page;
        // 0 means unlimited, so no fallback nav is needed.
        $has_cap = $load_more_max > 0 && $load_more_max < $max_pages;
        ?>
        <div
            class="kk-pagination kk-pagination-loader"
            data-kk-pagination
            data-mode="<?php echo esc_attr($mode); ?>"
            data-endpoint="<?php echo esc_attr($endpoint); ?>"
            data-query="<?php echo esc_attr((string) wp_json_encode($queryVars)); ?>"
            data-page="1"
            data-max-pages="<?php echo esc_attr((string) $max_pages); ?>"
            data-load-more-max="<?php echo esc_attr((string) $load_more_max); ?>"
            data-loading-style="<?php echo esc_attr(self::loadingStyle()); ?>"
            data-per-page="<?php echo esc_attr((string) self::perPage($query)); ?>"
        >
            <button type="button" class="kk-load-more" data-kk-load-more>
                <span class="kk-load-more-icon" aria-hidden="true" hidden><?php echo \KoperasiKita\Icons\Icon::svg('loader', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="kk-load-more-label"><?php echo esc_html(self::loadMoreText()); ?></span>
            </button>
            <p class="kk-pagination-status" data-kk-pagination-status aria-live="polite"></p>
            <p class="kk-pagination-end" data-kk-pagination-end hidden><?php esc_html_e('No more posts.', 'koperasikita'); ?></p>
            <?php if ($has_cap) : ?>
                <div class="kk-pagination-fallback" data-kk-pagination-fallback hidden>
                    <?php echo self::numberedNavHtml($query, $load_more_max); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The numbered pagination HTML for the fallback nav: shown when
     * the Load More cap is reached and there are still pages left.
     * The current page is the cap itself — when the nav is revealed
     * the reader has loaded exactly that many pages — so the marker
     * is right without any client-side fix-up.
     */
    public static function numberedNavHtml(?\WP_Query $query = null, int $current = 1): string
    {
        $max_pages = self::maxPages($query);
        $current = max(1, min($max_pages, $current));

        if ($max_pages < 2) {
            return '';
        }

        try {
            $links = paginate_links([
                'base' => str_replace('999999999', '%#%', esc_url(get_pagenum_link(999999999))),
                'format' => '?paged=%#%',
                'current' => $current,
                'total' => $max_pages,
                'mid_size' => 2,
                'prev_text' => self::prevText(),
                'next_text' => self::nextText(),
                'type' => 'plain',
            ]);
        } catch (\Throwable) {
            // A broken pagination link must never white-screen the
            // page: the loader still works, just without the fallback.
            return '';
        }

        if (!$links || !is_string($links)) {
            return '';
        }

        return '<nav class="navigation pagination kk-pagination" aria-label="' . esc_attr(__('Posts navigation', 'koperasikita')) . '"><h2 class="screen-reader-text">' . esc_html__('Posts navigation', 'koperasikita') . '</h2><div class="nav-links">' . $links . '</div></nav>';
    }
}

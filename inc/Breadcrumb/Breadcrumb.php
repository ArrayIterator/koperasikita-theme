<?php

declare(strict_types=1);

namespace KoperasiKita\Breadcrumb;

final class Breadcrumb
{
    public static function register(): void
    {
        // Rendering is explicit: templates call Breadcrumb::render() where the
        // trail belongs, so no hook is attached here.
    }

    public static function render(): void
    {
        if (!(bool) get_theme_mod('kk_breadcrumb_enabled', true)) {
            return;
        }

        if (is_front_page() && !(bool) get_theme_mod('kk_breadcrumb_home', false)) {
            return;
        }

        /* Per-page-type visibility: the master switch stays on, but
         * each type can be hidden on its own. The front page is
         * covered by kk_breadcrumb_home above. */
        if (is_single() && !(bool) get_theme_mod('kk_breadcrumb_post', true)) {
            return;
        }

        if (is_page() && !(bool) get_theme_mod('kk_breadcrumb_page', true)) {
            return;
        }

        if (is_archive() && !(bool) get_theme_mod('kk_breadcrumb_archive', true)) {
            return;
        }

        if (is_search() && !(bool) get_theme_mod('kk_breadcrumb_search', true)) {
            return;
        }

        if (is_404() && !(bool) get_theme_mod('kk_breadcrumb_404', true)) {
            return;
        }

        $separator = (string) get_theme_mod('kk_breadcrumb_separator', '›');

        // Each crumb is ['label' => string, 'url' => string|null].
        // A null URL marks the current page (rendered as text, not a link).
        $crumbs = [
            [
                'label' => __('Home', 'koperasikita'),
                'url'   => home_url('/'),
            ],
        ];

        if (is_front_page()) {
            // Home is the current page: render it as text, not a link.
            $crumbs[0]['url'] = null;
        } elseif (is_singular('agenda')) {
            // Agenda items have no taxonomy, so link the Agenda archive
            // between Home and the item title.
            $archive_url = get_post_type_archive_link('agenda');

            if (is_string($archive_url) && $archive_url !== '') {
                $crumbs[] = [
                    'label' => __('Agenda', 'koperasikita'),
                    'url'   => $archive_url,
                ];
            }

            $crumbs[] = [
                'label' => get_the_title(),
                'url'   => null,
            ];
        } elseif (is_single()) {
            $categories = get_the_category();

            if (!empty($categories)) {
                $category = $categories[0];

                // Ancestor categories, top-level first.
                $ancestor_ids = array_reverse(array_unique(get_ancestors($category->term_id, 'category')));

                foreach ($ancestor_ids as $ancestor_id) {
                    $ancestor = get_category((int) $ancestor_id);

                    if ($ancestor && !is_wp_error($ancestor)) {
                        $crumbs[] = [
                            'label' => $ancestor->name,
                            'url'   => get_category_link($ancestor),
                        ];
                    }
                }

                $crumbs[] = [
                    'label' => $category->name,
                    'url'   => get_category_link($category),
                ];
            }

            $crumbs[] = [
                'label' => get_the_title(),
                'url'   => null,
            ];
        } elseif (is_page()) {
            // Parent pages, top-level first.
            $ancestor_ids = array_reverse(array_unique(get_post_ancestors(get_the_ID())));

            foreach ($ancestor_ids as $ancestor_id) {
                $url = get_permalink((int) $ancestor_id);

                if (is_string($url) && $url !== '') {
                    $crumbs[] = [
                        'label' => get_the_title((int) $ancestor_id),
                        'url'   => $url,
                    ];
                }
            }

            $crumbs[] = [
                'label' => get_the_title(),
                'url'   => null,
            ];
        } elseif (is_category() || is_tag() || is_tax()) {
            $crumbs[] = [
                'label' => single_term_title('', false),
                'url'   => null,
            ];
        } elseif (is_search()) {
            $crumbs[] = [
                /* translators: %s: Search query. */
                'label' => sprintf(__('Search results for "%s"', 'koperasikita'), get_search_query()),
                'url'   => null,
            ];
        } elseif (is_404()) {
            $crumbs[] = [
                'label' => __('Page not found', 'koperasikita'),
                'url'   => null,
            ];
        } elseif (is_home()) {
            $posts_page_id = (int) get_option('page_for_posts');

            $crumbs[] = [
                'label' => $posts_page_id > 0 ? get_the_title($posts_page_id) : __('Latest news', 'koperasikita'),
                'url'   => null,
            ];
        } elseif (is_post_type_archive()) {
            $crumbs[] = [
                'label' => post_type_archive_title('', false),
                'url'   => null,
            ];
        } elseif (is_author()) {
            $author = get_queried_object();
            $label  = ($author instanceof \WP_User) ? $author->display_name : get_the_author();

            $crumbs[] = [
                'label' => $label,
                'url'   => null,
            ];
        } elseif (is_date()) {
            $crumbs[] = [
                'label' => self::dateArchiveLabel(),
                'url'   => null,
            ];
        } elseif (is_archive()) {
            $crumbs[] = [
                'label' => self::archiveTitleWithoutPrefix(),
                'url'   => null,
            ];
        } else {
            $crumbs[] = [
                'label' => get_the_title(),
                'url'   => null,
            ];
        }

        $items    = [];
        $position = 0;

        foreach ($crumbs as $crumb) {
            $position++;

            if ($position > 1) {
                $items[] = '<li class="kk-breadcrumb-separator" aria-hidden="true">'
                    . esc_html($separator)
                    . '</li>';
            }

            $label = esc_html((string) $crumb['label']);

            if (isset($crumb['url']) && is_string($crumb['url']) && $crumb['url'] !== '') {
                $items[] = sprintf(
                    '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">'
                    . '<a itemprop="item" href="%s"><span itemprop="name">%s</span></a>'
                    . '<meta itemprop="position" content="%d">'
                    . '</li>',
                    esc_url($crumb['url']),
                    $label,
                    $position
                );
            } else {
                $items[] = sprintf(
                    '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">'
                    . '<span itemprop="name" aria-current="page">%s</span>'
                    . '<meta itemprop="position" content="%d">'
                    . '</li>',
                    $label,
                    $position
                );
            }
        }

        echo '<nav aria-label="' . esc_attr__('Breadcrumb', 'koperasikita') . '" class="kk-breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList"><ol>'
            . implode('', $items)
            . '</ol></nav>';
    }

    /**
     * Date archive label without the "Year: " / "Month: " / "Day: " prefix.
     */
    private static function dateArchiveLabel(): string
    {
        $year  = (int) get_query_var('year');
        $month = (int) get_query_var('monthnum');
        $day   = (int) get_query_var('day');

        if ($day > 0 && $month > 0 && $year > 0) {
            return date_i18n((string) get_option('date_format'), (int) mktime(0, 0, 0, $month, $day, $year));
        }

        if ($month > 0 && $year > 0) {
            return date_i18n('F Y', (int) mktime(0, 0, 0, $month, 1, $year));
        }

        if ($year > 0) {
            return (string) $year;
        }

        return __('Archives', 'koperasikita');
    }

    /**
     * Archive title with the "Prefix: " part stripped (e.g. "Archives: Agenda" -> "Agenda").
     */
    private static function archiveTitleWithoutPrefix(): string
    {
        $title = get_the_archive_title();
        $parts = explode(': ', $title, 2);

        return count($parts) === 2 ? $parts[1] : $title;
    }
}

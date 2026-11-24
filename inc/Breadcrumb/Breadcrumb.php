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

        $separator = (string) get_theme_mod('kk_breadcrumb_separator', '›');

        $crumbs = [];

        $crumbs[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(home_url('/')),
            esc_html__('Home', 'koperasikita')
        );

        if (is_single()) {
            $categories = get_the_category();

            if (!empty($categories)) {
                $category = $categories[0];
                $crumbs[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(get_category_link($category)),
                    esc_html($category->name)
                );
            }

            $crumbs[] = sprintf(
                '<span aria-current="page">%s</span>',
                esc_html(get_the_title())
            );
        } elseif (is_category() || is_tag() || is_tax()) {
            $crumbs[] = sprintf(
                '<span aria-current="page">%s</span>',
                esc_html(single_term_title('', false))
            );
        } elseif (is_archive()) {
            $crumbs[] = sprintf(
                '<span aria-current="page">%s</span>',
                esc_html(get_the_archive_title())
            );
        } else {
            $crumbs[] = sprintf(
                '<span aria-current="page">%s</span>',
                esc_html(get_the_title())
            );
        }

        $items = [];

        foreach ($crumbs as $index => $crumb) {
            if ($index > 0) {
                $items[] = '<li class="kk-breadcrumb-separator" aria-hidden="true">'
                    . esc_html($separator)
                    . '</li>';
            }

            $items[] = '<li>' . $crumb . '</li>';
        }

        echo '<nav aria-label="Breadcrumb" class="kk-breadcrumb"><ol>'
            . implode('', $items)
            . '</ol></nav>';
    }
}

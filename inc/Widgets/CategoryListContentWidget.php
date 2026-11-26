<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Category List Content widget: posts from one category, in one of
 * five layouts (Featured, Grid, List, Featured Right, List Column).
 * The title is a custom title, or the category name when empty, and
 * "Enable Link To" links it to the category archive.
 */
final class CategoryListContentWidget extends AbstractTermListContentWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_category_list_content',
            __('Koperasi Kita Category List Content', 'koperasikita'),
            [
                'description' => __('Posts from one category, in a Featured, Grid, List, Featured Right or List Column layout.', 'koperasikita'),
                'classname' => 'kk-category-list-content-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    protected function taxonomy(): string
    {
        return 'category';
    }

    protected function termLabel(): string
    {
        return __('Category', 'koperasikita');
    }
}

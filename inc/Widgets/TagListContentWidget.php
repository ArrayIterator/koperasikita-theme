<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

/**
 * Tag List Content widget: posts from one tag, in one of five layouts
 * (Featured, Grid, List, Featured Right, List Column). The title is
 * a custom title, or the tag name when empty, and "Enable Link To"
 * links it to the tag archive.
 */
final class TagListContentWidget extends AbstractTermListContentWidget
{
    public function __construct()
    {
        parent::__construct(
            'kk_tag_list_content',
            __('Koperasi Kita Tag List Content', 'koperasikita'),
            [
                'description' => __('Posts from one tag, in a Featured, Grid, List, Featured Right or List Column layout.', 'koperasikita'),
                'classname' => 'kk-tag-list-content-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    protected function taxonomy(): string
    {
        return 'post_tag';
    }

    protected function termLabel(): string
    {
        return __('Tag', 'koperasikita');
    }
}

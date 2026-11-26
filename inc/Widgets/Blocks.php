<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Assets\ViteLoader;
use KoperasiKita\Features\Features;
use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Exposes the theme widgets as blocks in their own inserter category.
 *
 * The widgets stay classic WP_Widget classes (they work in the classic
 * widgets screen and the Customizer too); this registrar only adds a
 * "Koperasi Kita" block category and one block type per widget, each
 * with a Dashicons icon and a render callback that renders the widget
 * through the_widget(). The block editor UI (InspectorControls with
 * the widget fields) lives in src/ts/widget-blocks.ts.
 *
 * Gold/Markets blocks register only while the Markets feature is on,
 * Weather only while the Weather feature is on — the same gating as
 * the classic widgets, so a block never appears for a feature that
 * is off.
 */
final class Blocks
{
    public const CATEGORY_SLUG = 'koperasi-kita';

    public static function register(): void
    {
        add_filter('block_categories_all', [self::class, 'addCategory'], 10, 2);

        self::registerAssets();

        foreach (self::definitions() as $slug => $definition) {
            register_block_type('koperasi-kita/' . $slug, [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'category' => self::CATEGORY_SLUG,
                'attributes' => $definition['attributes'],
                'editor_script' => 'kk-widget-blocks',
                'editor_style' => 'kk-widget-blocks-editor',
                'render_callback' => static function (array $attributes) use ($definition): string {
                    return self::render($attributes, $definition);
                },
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public static function addCategory(array $categories, mixed $post): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === self::CATEGORY_SLUG) {
                return $categories;
            }
        }

        // First in the inserter, not last: the theme blocks are the
        // ones the editor reaches for most, so the Koperasi Kita
        // category sits at the very top of the block list.
        array_unshift($categories, [
            'slug' => self::CATEGORY_SLUG,
            'title' => __('Koperasi Kita', 'koperasikita'),
            'icon' => 'admin-customizer',
        ]);

        return $categories;
    }

    /**
     * Block slug => definition. `map` translates block attributes
     * (camelCase, the editor convention) to widget instance keys
     * (snake_case, the WP_Widget convention).
     *
     * @return array<string, array{class: class-string<WP_Widget>, icon: string, title: string, description: string, attributes: array<string, mixed>, map: array<string, string>}>
     */
    private static function definitions(): array
    {
        $storyAttributes = [
            'title' => ['type' => 'string', 'default' => ''],
            'headerStyle' => ['type' => 'string', 'default' => 'classic'],
            'count' => ['type' => 'number', 'default' => 5],
            'layout' => ['type' => 'string', 'default' => 'list'],
            'showThumbnail' => ['type' => 'boolean', 'default' => true],
        ];
        $storyMap = [
            'title' => 'title',
            'headerStyle' => 'header_style',
            'count' => 'count',
            'layout' => 'layout',
            'showThumbnail' => 'show_thumbnail',
        ];

        $definitions = [
            'recent-news' => [
                'class' => RecentPostsWidget::class,
                'icon' => 'megaphone',
                'title' => __('Recent News', 'koperasikita'),
                'description' => __('The latest published posts, newest first.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'popular-news' => [
                'class' => PopularPostsWidget::class,
                'icon' => 'chart-line',
                'title' => __('Popular News', 'koperasikita'),
                'description' => __('Most discussed posts, ranked by comment count.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'related-news' => [
                'class' => RelatedPostsWidget::class,
                'icon' => 'networking',
                'title' => __('Related News', 'koperasikita'),
                'description' => __('Posts related to the one being read, or the latest posts elsewhere.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'editors-picks' => [
                'class' => EditorsPicksWidget::class,
                'icon' => 'star-filled',
                'title' => __('Editor’s Picks', 'koperasikita'),
                'description' => __('Numbered list of posts from the categories and tags you pick, newest first.', 'koperasikita'),
                'attributes' => array_merge($storyAttributes, [
                    'categoryIds' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'number']],
                    'tagIds' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'number']],
                    'showNumeric' => ['type' => 'boolean', 'default' => true],
                    'showDate' => ['type' => 'boolean', 'default' => true],
                    'showCategories' => ['type' => 'boolean', 'default' => false],
                ]),
                'map' => array_merge($storyMap, [
                    'categoryIds' => 'category_ids',
                    'tagIds' => 'tag_ids',
                    'showNumeric' => 'show_numeric',
                    'showDate' => 'show_date',
                    'showCategories' => 'show_categories',
                ]),
            ],
            'world-clock' => [
                'class' => WorldClockWidget::class,
                'icon' => 'clock',
                'title' => __('World Clock', 'koperasikita'),
                'description' => __('Current time in the timezones you list, one per line.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic'],
                    'timezones' => ['type' => 'string', 'default' => WorldClockWidget::DEFAULT_TIMEZONE],
                    'showDate' => ['type' => 'boolean', 'default' => true],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'timezones' => 'timezones',
                    'showDate' => 'show_date',
                ],
            ],
            'pages' => [
                'class' => PagesWidget::class,
                'icon' => 'page',
                'title' => __('Pages', 'koperasikita'),
                'description' => __('A list of page links, in menu order, by date or by title.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic'],
                    'count' => ['type' => 'number', 'default' => 5],
                    'sortOrder' => ['type' => 'string', 'default' => 'menu_order'],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'count' => 'count',
                    'sortOrder' => 'sort_order',
                ],
            ],
        ];

        if (Features::isEnabled('markets')) {
            $definitions['gold'] = [
                'class' => GoldWidget::class,
                'icon' => 'star-filled',
                'title' => __('Gold Prices', 'koperasikita'),
                'description' => __('Gold prices for five brands in an auto-scrolling slider.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic'],
                    'brands' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'brands' => 'brands',
                ],
            ];
            $definitions['markets'] = [
                'class' => MarketsWidget::class,
                'icon' => 'chart-line',
                'title' => __('Markets', 'koperasikita'),
                'description' => __('Gold, stock and crypto prices from the Markets providers.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic'],
                    'markets' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'showChange' => ['type' => 'boolean', 'default' => true],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'markets' => 'markets',
                    'showChange' => 'show_change',
                ],
            ];
        }

        if (Features::isEnabled('weather')) {
            $definitions['weather'] = [
                'class' => WeatherWidget::class,
                'icon' => 'cloud',
                'title' => __('Weather', 'koperasikita'),
                'description' => __('Current weather for a city, with a location picker.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic'],
                    'defaultCity' => ['type' => 'string', 'default' => 'Padang'],
                    'showDetails' => ['type' => 'boolean', 'default' => false],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'defaultCity' => 'default_city',
                    'showDetails' => 'show_details',
                ],
            ];
        }

        return $definitions;
    }

    private static function registerAssets(): void
    {
        $js = ViteLoader::js('src/ts/widget-blocks.ts');

        if ($js !== '') {
            wp_register_script(
                'kk-widget-blocks',
                $js,
                ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n', 'wp-server-side-render'],
                KK_VERSION,
                true
            );
            wp_localize_script('kk-widget-blocks', 'kkWidgetBlocks', [
                'blocks' => array_keys(self::definitions()),
                'goldBrands' => self::optionList(Gold::brands()),
                'marketLabels' => self::optionList(Markets::labels()),
            ]);
        }

        $css = ViteLoader::css('src/css/widget-blocks.css');

        if ($css !== '') {
            wp_register_style('kk-widget-blocks-editor', $css, ['wp-edit-blocks'], KK_VERSION);
        }
    }

    /**
     * @param array<string, string> $options Slug => label.
     * @return array<int, array{value: string, label: string}>
     */
    private static function optionList(array $options): array
    {
        $list = [];

        foreach ($options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array{class: class-string<WP_Widget>, map: array<string, string>} $definition
     */
    private static function render(array $attributes, array $definition): string
    {
        $instance = [];

        foreach ($definition['map'] as $attribute => $key) {
            if (array_key_exists($attribute, $attributes)) {
                $instance[$key] = $attributes[$attribute];
            }
        }

        // Run the widget's own update() so block attributes get exactly
        // the same validation as the classic widget form.
        /** @var WP_Widget $widget */
        $widget = new $definition['class']();
        $instance = $widget->update($instance, []);

        ob_start();
        the_widget($definition['class'], $instance);
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }
}

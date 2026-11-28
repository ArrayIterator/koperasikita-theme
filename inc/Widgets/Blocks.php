<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Assets\ViteLoader;
use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Features;
use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;
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
 * Weather only while the Weather feature is on - the same gating as
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
                // The inserter preview: without an `example` the
                // inserter shows "No Preview Available" for the block.
                // The example is just the attribute defaults, so the
                // preview is the block as first inserted - a real
                // ServerSideRender preview, not a placeholder.
                'example' => ['attributes' => self::exampleAttributes($definition['attributes'])],
                'editor_script' => 'kk-widget-blocks',
                // Both editor styles load inside the iframe canvas:
                // the placeholder/field styles and the editor bundle
                // (src/css/editor.css) with the frontend widget
                // styles, so the ServerSideRender preview looks like
                // the live site. Core ALSO enqueues every block's
                // editor_style handles in the OUTER admin document on
                // `enqueue_block_editor_assets`
                // (wp_enqueue_registered_block_scripts_and_styles),
                // so the canvas bundle's bare body / h1-h3 rules would
                // restyle the WordPress frame there - the dequeue in
                // Enqueue::dequeueCanvasBundleFromOuterPage strips
                // `kk-editor` from the outer queue (priority 20 on the
                // same hook); the iframe keeps its own copy. Styles enqueued on
                // `enqueue_block_editor_assets` never enter the
                // canvas, which is why previews used to render
                // unstyled (plain numbers, green titles, no rules).
                'editor_style' => ['kk-widget-blocks-editor', 'kk-editor'],
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
            'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
            'count' => ['type' => 'number', 'default' => 5],
            'layout' => ['type' => 'string', 'default' => 'list'],
            'showThumbnail' => ['type' => 'boolean', 'default' => true],
            'marginTop' => ['type' => 'number', 'default' => 0],
            'marginBottom' => ['type' => 'number', 'default' => 10],
        ];
        $storyMap = [
            'title' => 'title',
            'headerStyle' => 'header_style',
            'count' => 'count',
            'layout' => 'layout',
            'showThumbnail' => 'show_thumbnail',
            'marginTop' => 'margin_top',
            'marginBottom' => 'margin_bottom',
        ];
        // Every other block carries the same two margin attributes,
        // both defaulting to 0 - only the article-list widgets
        // default the bottom margin to 10px (see $storyAttributes).
        $marginAttributes = [
            'marginTop' => ['type' => 'number', 'default' => 0],
            'marginBottom' => ['type' => 'number', 'default' => 0],
        ];
        $marginMap = [
            'marginTop' => 'margin_top',
            'marginBottom' => 'margin_bottom',
        ];

        $definitions = [
            'recent-news' => [
                'class' => RecentPostsWidget::class,
                'icon' => 'megaphone',
                'title' => __('KK Recent News', 'koperasikita'),
                'description' => __('The latest published posts, newest first.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'popular-news' => [
                'class' => PopularPostsWidget::class,
                'icon' => 'chart-line',
                'title' => __('KK Popular News', 'koperasikita'),
                'description' => __('Most discussed posts, ranked by comment count.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'related-news' => [
                'class' => RelatedPostsWidget::class,
                'icon' => 'networking',
                'title' => __('KK Related News', 'koperasikita'),
                'description' => __('Posts related to the one being read, or the latest posts elsewhere.', 'koperasikita'),
                'attributes' => $storyAttributes,
                'map' => $storyMap,
            ],
            'editors-picks' => [
                'class' => EditorsPicksWidget::class,
                'icon' => 'star-filled',
                'title' => __('KK Post By Terms', 'koperasikita'),
                'description' => __('Posts from the categories and tags you pick, in your chosen order.', 'koperasikita'),
                'attributes' => array_merge($storyAttributes, [
                    'categoryIds' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'tagIds' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'order' => ['type' => 'string', 'default' => 'date_desc'],
                ]),
                'map' => array_merge($storyMap, [
                    'categoryIds' => 'category_ids',
                    'tagIds' => 'tag_ids',
                    'order' => 'order',
                ]),
            ],
            'pages' => [
                'class' => PagesWidget::class,
                'icon' => 'page',
                'title' => __('KK Pages', 'koperasikita'),
                'description' => __('A list of page links, in menu order, by date or by title.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
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
            'newsletter' => [
                'class' => NewsletterWidget::class,
                'icon' => 'mail',
                'title' => __('KK Newsletter', 'koperasikita'),
                'description' => __('The newsletter subscribe form, for any widget area.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'text' => ['type' => 'string', 'default' => NewsletterWidget::defaultText()],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'text' => 'text',
                ],
            ],
            'quote' => [
                'class' => QuoteWidget::class,
                'icon' => 'message-square',
                'title' => __('KK Quote', 'koperasikita'),
                'description' => __('A single quote card, for any widget area.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'quote' => ['type' => 'string', 'default' => ''],
                    'author' => ['type' => 'string', 'default' => ''],
                    'role' => ['type' => 'string', 'default' => ''],
                    'linkUrl' => ['type' => 'string', 'default' => ''],
                    'linkLabel' => ['type' => 'string', 'default' => ''],
                    'fontSize' => ['type' => 'number', 'default' => 19],
                    'italic' => ['type' => 'boolean', 'default' => false],
                    'bgColor' => ['type' => 'string', 'default' => ''],
                    'textColor' => ['type' => 'string', 'default' => ''],
                    'roleColor' => ['type' => 'string', 'default' => ''],
                    'linkColor' => ['type' => 'string', 'default' => ''],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'quote' => 'quote',
                    'author' => 'author',
                    'role' => 'role',
                    'linkUrl' => 'link_url',
                    'linkLabel' => 'link_label',
                    'fontSize' => 'font_size',
                    'italic' => 'italic',
                    'bgColor' => 'bg_color',
                    'textColor' => 'text_color',
                    'roleColor' => 'role_color',
                    'linkColor' => 'link_color',
                ],
            ],
            'category-list-content' => [
                'class' => CategoryListContentWidget::class,
                'icon' => 'category',
                'title' => __('KK Category List Content', 'koperasikita'),
                'description' => __('Posts from one category, in a Featured, Grid, List, Featured Right or List Column layout.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'termId' => ['type' => 'string', 'default' => ''],
                    'layout' => ['type' => 'string', 'default' => 'featured'],
                    'linkTo' => ['type' => 'boolean', 'default' => true],
                    'count' => ['type' => 'number', 'default' => 5],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'termId' => 'term_id',
                    'layout' => 'layout',
                    'linkTo' => 'link_to',
                    'count' => 'count',
                ],
            ],
            'tag-list-content' => [
                'class' => TagListContentWidget::class,
                'icon' => 'tag',
                'title' => __('KK Tag List Content', 'koperasikita'),
                'description' => __('Posts from one tag, in a Featured, Grid, List, Featured Right or List Column layout.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'termId' => ['type' => 'string', 'default' => ''],
                    'layout' => ['type' => 'string', 'default' => 'featured'],
                    'linkTo' => ['type' => 'boolean', 'default' => true],
                    'count' => ['type' => 'number', 'default' => 5],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'termId' => 'term_id',
                    'layout' => 'layout',
                    'linkTo' => 'link_to',
                    'count' => 'count',
                ],
            ],
            'category-summary' => [
                'class' => CategorySummaryWidget::class,
                'icon' => 'columns',
                'title' => __('KK Category Summary', 'koperasikita'),
                'description' => __('The latest post from each picked category, side by side in columns.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'categoryIds' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'count' => ['type' => 'number', 'default' => 1],
                    'showExcerpt' => ['type' => 'boolean', 'default' => true],
                    'showTime' => ['type' => 'boolean', 'default' => true],
                    'indexLabel' => ['type' => 'string', 'default' => ''],
                    'indexUrl' => ['type' => 'string', 'default' => ''],
                ],
                'map' => [
                    'title' => 'title',
                    'categoryIds' => 'category_ids',
                    'count' => 'count',
                    'showExcerpt' => 'show_excerpt',
                    'showTime' => 'show_time',
                    'indexLabel' => 'index_label',
                    'indexUrl' => 'index_url',
                ],
            ],
        ];

        if (Features::isEnabled('markets')) {
            $definitions['gold'] = [
                'class' => GoldWidget::class,
                'icon' => 'star-filled',
                'title' => __('KK Gold Prices', 'koperasikita'),
                'description' => __('Gold prices for Indonesian gold brands in an auto-scrolling slider.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'brands' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'style' => ['type' => 'string', 'default' => 'vertical-carousel'],
                    'colorStyle' => ['type' => 'string', 'default' => 'auto'],
                    'autoplay' => ['type' => 'boolean', 'default' => true],
                    'interval' => ['type' => 'number', 'default' => 6],
                    'itemsPerView' => ['type' => 'number', 'default' => 1],
                    'showDots' => ['type' => 'boolean', 'default' => false],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'brands' => 'brands',
                    'style' => 'style',
                    'colorStyle' => 'color_style',
                    'autoplay' => 'autoplay',
                    'interval' => 'interval',
                    'itemsPerView' => 'items_per_view',
                    'showDots' => 'show_dots',
                ],
            ];
            $definitions['data-desk'] = [
                'class' => DataDeskWidget::class,
                'icon' => 'chart-bar',
                'title' => __('KK Data Desk', 'koperasikita'),
                'description' => __('One market symbol as a data desk panel: price chart and stats. Pick a template.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'template' => ['type' => 'string', 'default' => ''],
                    // Per-widget overrides on top of the template:
                    // an empty symbol / 0 days means "use the
                    // template's", a hide flag hides that stat
                    // for this widget only.
                    'symbolOverride' => ['type' => 'string', 'default' => ''],
                    'daysOverride' => ['type' => 'number', 'default' => 0],
                    'hideSell' => ['type' => 'boolean', 'default' => false],
                    'hideBuyback' => ['type' => 'boolean', 'default' => false],
                    'hidePerformance' => ['type' => 'boolean', 'default' => false],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'template' => 'template',
                    'symbolOverride' => 'symbol_override',
                    'daysOverride' => 'days_override',
                    'hideSell' => 'hide_sell',
                    'hideBuyback' => 'hide_buyback',
                    'hidePerformance' => 'hide_performance',
                ],
            ];
            $definitions['markets'] = [
                'class' => MarketsWidget::class,
                'icon' => 'chart-line',
                'title' => __('KK Markets', 'koperasikita'),
                'description' => __('Gold, stock, forex, commodity and crypto prices from the Markets providers.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'markets' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'items' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'string']],
                    'showChange' => ['type' => 'boolean', 'default' => true],
                    'layout' => ['type' => 'string', 'default' => 'list'],
                    'visible' => ['type' => 'number', 'default' => 1],
                    'style' => ['type' => 'string', 'default' => 'default'],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'markets' => 'markets',
                    'items' => 'items',
                    'showChange' => 'show_change',
                    'layout' => 'layout',
                    'visible' => 'visible',
                    'style' => 'style',
                ],
            ];
            $definitions['yahoo-finance'] = [
                'class' => YahooFinanceWidget::class,
                'icon' => 'chart-line',
                'title' => __('KK Yahoo Finance', 'koperasikita'),
                'description' => __('Live IHSG index from Yahoo Finance (free, no API key).', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'showChange' => ['type' => 'boolean', 'default' => true],
                    'layout' => ['type' => 'string', 'default' => 'list'],
                    'visible' => ['type' => 'number', 'default' => 1],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'showChange' => 'show_change',
                    'layout' => 'layout',
                    'visible' => 'visible',
                ],
            ];
        }

        if (Features::isEnabled('weather')) {
            $definitions['weather'] = [
                'class' => WeatherWidget::class,
                'icon' => 'cloud',
                'title' => __('KK: Cuaca', 'koperasikita'),
                'description' => __('Current weather for a city, with a location picker.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => ''],
                    'headerStyle' => ['type' => 'string', 'default' => 'classic-top'],
                    'style' => ['type' => 'string', 'default' => 'auto'],
                    'defaultCity' => ['type' => 'string', 'default' => 'Padang'],
                    'showDetails' => ['type' => 'boolean', 'default' => false],
                    'showIcon' => ['type' => 'boolean', 'default' => true],
                    'tempAlign' => ['type' => 'string', 'default' => 'left'],
                    'tempUnit' => ['type' => 'string', 'default' => 'auto'],
                    'locations' => [
                        'type' => 'array',
                        'default' => [],
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'latitude' => ['type' => 'number'],
                                'longitude' => ['type' => 'number'],
                            ],
                        ],
                    ],
                ],
                'map' => [
                    'title' => 'title',
                    'headerStyle' => 'header_style',
                    'style' => 'style',
                    'defaultCity' => 'default_city',
                    'showDetails' => 'show_details',
                    'showIcon' => 'show_icon',
                    'tempAlign' => 'temp_align',
                    'tempUnit' => 'temp_unit',
                    'locations' => 'locations',
                ],
            ];
        }

        if (Features::isEnabled('agenda')) {
            $definitions['agenda'] = [
                'class' => AgendaWidget::class,
                'icon' => 'calendar',
                'title' => __('KK Agenda', 'koperasikita'),
                'description' => __('Upcoming cooperative events as a card that follows the site colour scheme; live events get a green dot, finished events are dimmed.', 'koperasikita'),
                'attributes' => [
                    'title' => ['type' => 'string', 'default' => __('Agenda Koperasi', 'koperasikita')],
                    'count' => ['type' => 'number', 'default' => 5],
                    'showPast' => ['type' => 'boolean', 'default' => true],
                    'showIcon' => ['type' => 'boolean', 'default' => true],
                    'icon' => ['type' => 'string', 'default' => 'users'],
                    'style' => ['type' => 'string', 'default' => 'auto'],
                    'bgColor' => ['type' => 'string', 'default' => ''],
                    'accentColor' => ['type' => 'string', 'default' => ''],
                    'linkTo' => ['type' => 'number', 'default' => 0],
                ],
                'map' => [
                    'title' => 'title',
                    'count' => 'count',
                    'showPast' => 'show_past',
                    'showIcon' => 'show_icon',
                    'icon' => 'icon',
                    'style' => 'style',
                    'bgColor' => 'bg_color',
                    'accentColor' => 'accent_color',
                    'linkTo' => 'link_to',
                ],
            ];
        }

        // Every block carries the two margin attributes: the story
        // blocks above already have theirs (bottom defaults to
        // 10px); the rest get the 0 / 0 pair here, so a block added
        // later cannot forget them.
        foreach ($definitions as $slug => $definition) {
            if (!isset($definition['attributes']['marginTop'])) {
                $definitions[$slug]['attributes'] = array_merge($definition['attributes'], $marginAttributes);
                $definitions[$slug]['map'] = array_merge($definition['map'], $marginMap);
            }
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
                'goldBrands' => self::optionList(array_intersect_key(Gold::brands(), array_flip(Gold::enabledBrands()))),
                'marketLabels' => self::optionList(Markets::labels()),
                'marketItems' => self::optionList(MarketsWidget::allItems()),
                'categories' => self::termOptions('category'),
                'tags' => self::termOptions('post_tag'),
                'dataDeskTemplates' => self::templateOptions(DataDesk::KIND_PANEL),
                // The Data Desk block's widget overrides: the
                // symbol list, and per template its symbol, days,
                // hidden stats and resolved stat labels, so the
                // inspector shows the template's values and the
                // effective show/hide state.
                'dataDeskSymbols' => self::optionList(DataDesk::symbols()),
                'dataDeskTemplateMeta' => DataDesk::templateMeta(),
                // The agenda block's icon picker lists every icon.
                'icons' => self::optionList(array_combine(Icon::names(), Icon::names())),
                // The locations field searches places through the
                // theme's own AJAX endpoint (kk_weather_search).
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => Ajax::nonce(),
            ]);
        }

        $css = ViteLoader::css('src/css/widget-blocks.css');

        if ($css !== '') {
            wp_register_style('kk-widget-blocks-editor', $css, ['wp-edit-blocks'], KK_VERSION);
        }

        // The editor bundle: the frontend styles the ServerSideRender
        // previews need inside the iframe canvas (tokens, base,
        // content, widgets, gold-markets, weather, variants, plus the
        // canvas shell/title rules). Registered here so it can be a
        // block `editor_style`; Enqueue::registerBlockEditor() loads
        // the outer preview bundle (editor-outer.css) in the outer
        // admin page instead - the canvas bundle's bare rules would
        // restyle the WordPress frame there.
        $editorCss = ViteLoader::css('src/css/editor.css');

        if ($editorCss !== '') {
            wp_register_style('kk-editor', $editorCss, ['wp-edit-blocks'], KK_VERSION);
        }
    }

    /**
     * The inserter-preview attributes for a block: its attribute
     * defaults, so the example preview is the block as first
     * inserted.
     *
     * @param array<string, array<string, mixed>> $attributes
     * @return array<string, mixed>
     */
    private static function exampleAttributes(array $attributes): array
    {
        $example = [];

        foreach ($attributes as $name => $schema) {
            if (array_key_exists('default', $schema)) {
                $example[$name] = $schema['default'];
            }
        }

        return $example;
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
     * Data Desk templates of one kind as select options (id => name),
     * for the Data Desk block template picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private static function templateOptions(string $kind): array
    {
        $list = [];

        foreach (DataDesk::templatesOfKind($kind) as $id => $template) {
            $list[] = ['value' => (string) $id, 'label' => DataDesk::displayName($template)];
        }

        return $list;
    }

    /**
     * Terms of one taxonomy as select options (id => name), for the
     * Category / Tag List Content block pickers.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private static function termOptions(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $list = [];

        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $list[] = ['value' => (string) $term->term_id, 'label' => $term->name];
            }
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

        // Renders served over REST are the block editor (canvas and
        // inserter preview): run them in preview mode, so the price
        // providers serve their cached / last-good value and never
        // burn an upstream timeout on a cold transient. A frontend
        // page render is never a REST request, so it still refreshes
        // live. The flag is reset even when the widget fatals.
        $preview = defined('REST_REQUEST') && REST_REQUEST;

        if ($preview) {
            Markets::setPreviewMode(true);
        }

        try {
            ob_start();
            the_widget($definition['class'], $instance);
            $html = ob_get_clean();
        } finally {
            if ($preview) {
                Markets::setPreviewMode(false);
            }
        }

        return is_string($html) ? $html : '';
    }
}

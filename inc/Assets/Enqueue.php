<?php

declare(strict_types=1);

namespace KoperasiKita\Assets;

use KoperasiKita\Admin\AdminMenu;
use KoperasiKita\Features\Features;
use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Pwa;
use KoperasiKita\Setup\Setup;

final class Enqueue
{
    public static function register(): void
    {
        if (self::isCombinedCss()) {
            self::enqueueCombinedStyles();
        } else {
            self::enqueueSplitStyles();
        }

        Fonts::enqueue();

        $js = ViteLoader::js('src/ts/main.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-main', $js, [], KK_VERSION, true);
        }

        /* Feature styles live in the split/combined chain now (see
         * splitStyleEntries): they are concatenated into the combined
         * file in combined mode and enqueued in order in split mode.
         * Only the scripts still enqueue separately here. */
        if (Features::isEnabled('markets')) {
            $goldJs = ViteLoader::js('src/ts/gold-markets.ts');

            if ($goldJs !== '') {
                wp_enqueue_script('kk-gold-markets', $goldJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('weather')) {
            $weatherJs = ViteLoader::js('src/ts/weather.ts');

            if ($weatherJs !== '') {
                wp_enqueue_script('kk-weather', $weatherJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('agenda') && self::needsAgendaAssets()) {
            $agendaJs = ViteLoader::js('src/ts/agenda.ts');

            if ($agendaJs !== '') {
                wp_enqueue_script('kk-agenda', $agendaJs, [], KK_VERSION, true);
            }
        }

        // The text-size control is injected by the `the_content` filter
        // only on singular post/page/agenda views, so its script loads
        // only there - nowhere else on the site.
        if (is_singular(['post', 'page', 'agenda'])) {
            $fontSizeJs = ViteLoader::js('src/ts/article-font-size.ts');

            if ($fontSizeJs !== '') {
                wp_enqueue_script('kk-article-font-size', $fontSizeJs, [], KK_VERSION, true);
            }
        }

        // The pagination loader only runs where an archive loop can
        // render it (archive / blog / search) and only when the mode
        // is load-more - page numbers and next/prev need no script.
        if ('load-more' === \KoperasiKita\Features\Pagination::mode() && (is_archive() || is_home() || is_search())) {
            $paginationJs = ViteLoader::js('src/ts/pagination.ts');

            if ($paginationJs !== '') {
                wp_enqueue_script('kk-pagination', $paginationJs, [], KK_VERSION, true);
            }
        }

        wp_localize_script('kk-main', 'kkData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('koperasikita_nonce'),
            'isBookmarksEnabled' => Features::isEnabled('bookmarks'),
            'isMarketsEnabled' => Features::isEnabled('markets'),
            'isWeatherEnabled' => Features::isEnabled('weather'),
            'weatherCacheLifetimeMin' => (int) get_theme_mod('kk_weather_cache_lifetime', 15),
            'weatherUnit' => (string) get_theme_mod('kk_weather_unit', 'celsius'),
            'isPwaEnabled' => Features::isEnabled('pwa'),
            'pwaPromptDelayMs' => Pwa::promptDelayMs(),
            'themeUrl' => KK_URI,
        ]);

        self::addModuleScriptFilter();

        Fonts::outputFontVariables();
    }

    /**
     * Registers the script-tag filter that loads every Vite JS entry as an
     * ES module, at most once per request. The Customizer preview fires
     * both wp_enqueue_scripts and customize_preview_init, so register()
     * and registerCustomizerPreview() both call this.
     *
     * Two reasons, both fatal when an entry loads as a plain <script>:
     * (1) Vite's default 'es' output splits any module shared by two
     * entries (src/ts/api.ts is imported by weather, admin and
     * customizer-preview) into a separate chunk, and the entries then
     * start with a top-level `import` - a SyntaxError as a plain
     * <script>. (2) Even entries without imports leak their top-level
     * names into the one shared global scope, and Vite's minifier
     * reuses single-letter names across bundles: gold-markets'
     * `function I` vs main's `const I` threw "Identifier 'I' has
     * already been declared" and killed main.js - ticker, clock,
     * dark-mode toggle, search overlay, mobile nav, bookmarks, share
     * copy - on every page. Module scope gives each entry its own
     * scope, so no entry can ever collide with another. Never enqueue
     * a JS entry as a classic script (see vite.config.ts).
     */
    private static bool $moduleScriptFilterAdded = false;

    private static function addModuleScriptFilter(): void
    {
        if (self::$moduleScriptFilterAdded) {
            return;
        }

        self::$moduleScriptFilterAdded = true;

        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if (in_array($handle, ['kk-main', 'kk-gold-markets', 'kk-weather', 'kk-agenda', 'kk-article-font-size', 'kk-pagination', 'kk-pwa', 'kk-admin', 'kk-customizer-preview', 'kk-customizer-controls', 'kk-widget-blocks'], true) && !str_contains($tag, 'type="module"')) {
                // Load these entries as modules. Vite's default 'es'
                // output splits any module shared by two entries
                // (src/ts/api.ts is imported by weather, admin and
                // customizer-preview) into a separate chunk, and the
                // entries then start with a top-level `import` - a
                // SyntaxError as a plain <script>, which killed the
                // whole file: weather.js lost the picker, the top-bar
                // slot and the widget refresh; admin.js lost the Fonts
                // management controls; customizer-preview.js lost every
                // live-preview binding. And even import-free entries
                // collide in the shared global scope (gold-markets'
                // `function I` vs main's `const I` killed main.js on
                // every page). Module scripts are deferred by default,
                // so the DOMContentLoaded boots still run (admin.ts
                // guards on document.readyState, and the preview only
                // binds wp.customize, which its 'customize-preview'
                // dependency loads first).
                return str_replace(' src=', ' type="module" src=', $tag);
            }

            if ($handle !== 'kk-main' || str_contains($tag, ' defer')) {
                return $tag;
            }

            return str_replace(' src=', ' defer src=', $tag);
        }, 10, 2);
    }

    /**
     * The split stylesheets, in the exact order of the sections in the
     * old app.css monolith - the cascade depends on it. The shell
     * (reset, tokens, base, header, cards, footer, overlays, variants,
     * responsive) loads on every page. Home-only and content styles
     * load only where their templates can appear.
     */
    /**
     * Whether the "Serve Combined CSS" toggle (Advanced Performance &
     * Cleanup) is on. When it is, the split chain is replaced by one
     * combined stylesheet (handle kk-app).
     */
    public static function isCombinedCss(): bool
    {
        return (bool) get_theme_mod('kk_combine_css', true);
    }

    /**
     * The split stylesheets in cascade order for the current page,
     * as [handle => entry] pairs. The same list drives both modes:
     * split mode enqueues each file, combined mode concatenates them
     * in this order into one file. The conditionals (front page,
     * content styles) are identical in both modes, so the combined
     * file has exactly the rules the split chain would have served.
     *
     * @return array<string, string>
     */
    private static function splitStyleEntries(): array
    {
        $entries = [
            'kk-app' => 'src/css/app.css',
            'kk-tokens' => 'src/css/tokens.css',
            'kk-base' => 'src/css/base.css',
            'kk-header' => 'src/css/header.css',
        ];

        if (is_front_page()) {
            $entries['kk-home'] = 'src/css/home.css';
        }

        $entries['kk-cards'] = 'src/css/cards.css';

        if (is_front_page()) {
            $entries['kk-home-sections'] = 'src/css/home-sections.css';
        }

        if (self::needsContentStyles()) {
            $entries['kk-content'] = 'src/css/content.css';
            $entries['kk-widgets'] = 'src/css/widgets.css';
        }

        $entries['kk-footer'] = 'src/css/footer.css';
        $entries['kk-overlays'] = 'src/css/overlays.css';
        $entries['kk-variants'] = 'src/css/variants.css';
        $entries['kk-responsive'] = 'src/css/responsive.css';

        if (is_front_page()) {
            $entries['kk-newsletter'] = 'src/css/newsletter.css';
        }

        /* Feature styles, last in the cascade - exactly where they
         * used to load as separate files depending on kk-app (combined
         * mode) or kk-responsive (split mode). They are part of the
         * combined file too now: "combined" means every stylesheet
         * the page serves, not just the core chain. Each one loads
         * only under the same condition its feature used before. */
        if (Features::isEnabled('markets')) {
            $entries['kk-gold-markets'] = 'src/css/gold-markets.css';
        }

        if (Features::isEnabled('weather')) {
            $entries['kk-weather'] = 'src/css/weather.css';
        }

        if (Features::isEnabled('agenda') && self::needsAgendaAssets()) {
            $entries['kk-agenda'] = 'src/css/agenda.css';
        }

        if (Features::isEnabled('pwa')) {
            $entries['kk-pwa'] = 'src/css/pwa.css';
        }

        return $entries;
    }

    /**
     * Build (once per theme build) and enqueue the single combined
     * stylesheet: every split file for this page, concatenated in
     * cascade order, cached under uploads/koperasikita/ with a hash
     * of the source files' paths + mtimes + theme version, so a new
     * build automatically produces a new file. The handle is kk-app,
     * so the Customizer palette/font inline styles (attached to
     * kk-app) print right after the combined file, exactly where
     * they belong - no moveAppInlineStyles needed in combined mode.
     * Returns false (and the caller falls back to split mode) when
     * the file cannot be built.
     */
    private static function enqueueCombinedStyles(): void
    {
        $url = self::combinedCssUrl();

        if ($url === '') {
            self::enqueueSplitStyles();

            return;
        }

        wp_enqueue_style('kk-app', $url, [], KK_VERSION);
    }

    /**
     * URL of the cached combined stylesheet for this page, building
     * it when the sources changed. Empty string when it cannot be
     * built (missing dist files or an unwritable uploads dir).
     */
    public static function combinedCssUrl(): string
    {
        $paths = [];

        foreach (self::splitStyleEntries() as $entry) {
            $path = ViteLoader::cssPath($entry);

            if ($path === '') {
                return '';
            }

            $paths[] = $path;
        }

        $hash = md5(implode('|', $paths) . '|' . implode('|', array_map('filemtime', $paths)) . '|' . KK_VERSION);
        $upload = wp_get_upload_dir();

        if (!empty($upload['error'])) {
            return '';
        }

        $dir = trailingslashit((string) $upload['basedir']) . 'koperasikita';
        $filename = 'combined-' . $hash . '.css';
        $file = $dir . '/' . $filename;

        if (!is_readable($file)) {
            if (!wp_mkdir_p($dir)) {
                return '';
            }

            $combined = '';

            foreach ($paths as $path) {
                $combined .= '/* ' . basename($path) . " */\n" . (string) file_get_contents($path) . "\n";
            }

            if (file_put_contents($file, $combined) === false) {
                return '';
            }
        }

        return trailingslashit((string) $upload['baseurl']) . 'koperasikita/' . $filename;
    }

    private static function enqueueSplitStyles(): void
    {
        $deps = [];
        foreach (self::splitStyleEntries() as $handle => $entry) {
            self::enqueueStyle($handle, $entry, $deps);
            $deps = [$handle];
        }

        // Inline styles attached to kk-app (Customizer palette overrides,
        // font variables) must print after the split files, as they did
        // when app.css was one file - otherwise the :root defaults in
        // tokens.css would win over the user's font choice. The palette
        // overrides are added at priority 20, so move them late.
        add_action('wp_enqueue_scripts', [self::class, 'moveAppInlineStyles'], 30);
    }

    private static function enqueueStyle(string $handle, string $entry, array $deps): void
    {
        $css = ViteLoader::css($entry);

        if ($css !== '') {
            wp_enqueue_style($handle, $css, $deps, KK_VERSION);
        }
    }

    /**
     * Content styles cover archive / search / page layouts, the sidebar
     * and widget shells, single articles, breadcrumbs, comments,
     * pagination, the search form, 404 and bookmarks. Every frontend
     * template uses at least one of those - the front page renders the
     * news sidebar, index/archive/search/single render cards plus the
     * sidebar, pages render breadcrumbs, and the bookmarks/404 pages
     * are singular templates - so this is every frontend render.
     */
    private static function needsContentStyles(): bool
    {
        return is_front_page() || is_home() || is_archive() || is_search() || is_singular() || is_404();
    }

    /**
     * Re-attach any inline styles added to kk-app to the last split
     * stylesheet, so they keep winning over the :root defaults in
     * tokens.css exactly as they did when app.css was a single file.
     */
    public static function moveAppInlineStyles(): void
    {
        global $wp_styles;

        if (! isset($wp_styles->registered['kk-app'], $wp_styles->registered['kk-responsive'])) {
            return;
        }

        $app = $wp_styles->registered['kk-app'];
        $last = $wp_styles->registered['kk-responsive'];

        $after = $app->extra['after'] ?? [];

        if ($after === []) {
            return;
        }

        $app->extra['after'] = [];
        $last->extra['after'] = array_merge($last->extra['after'] ?? [], (array) $after);
    }

    /**
     * Agenda assets are only needed on the agenda archive, on a single
     * agenda item, or on a page that embeds the [kk_agenda_submit]
     * shortcode. Everywhere else the feature loads nothing, even when
     * it is enabled.
     */
    private static function needsAgendaAssets(): bool
    {
        if (is_post_type_archive(\KoperasiKita\Features\Agenda::POST_TYPE)) {
            return true;
        }

        if (is_singular(\KoperasiKita\Features\Agenda::POST_TYPE)) {
            return true;
        }

        if (is_singular()) {
            $post = get_queried_object();

            if ($post instanceof \WP_Post && has_shortcode($post->post_content, \KoperasiKita\Features\Agenda::SHORTCODE)) {
                return true;
            }
        }

        return false;
    }

    public static function registerAdmin(string $hookSuffix): void
    {
        // The admin entry starts with a top-level `import` (see
        // addModuleScriptFilter): without this the kk-admin tag is a
        // classic script and admin.js never runs. The frontend
        // register() never fires on admin pages, so the filter has to
        // be registered here too.
        self::addModuleScriptFilter();

        $isWidgets = str_contains($hookSuffix, 'widgets');
        $isCustomizer = str_contains($hookSuffix, 'customize');
        $isSetup = $hookSuffix === 'toplevel_page_' . Setup::PAGE_SLUG;

        if ($isSetup) {
            $setupCss = ViteLoader::css('src/css/setup.css');

            if ($setupCss !== '') {
                wp_enqueue_style('kk-setup', $setupCss, [], KK_VERSION);
            }

            /* The Sidebar Settings popup on this page (admin.ts
             * initSidebarSettingsPopup) needs the admin script +
             * stylesheet (popup + toast) and the shared nonce. */
            $adminCss = ViteLoader::css('src/ts/admin.ts');

            if ($adminCss !== '') {
                wp_enqueue_style('kk-admin', $adminCss, [], KK_VERSION);
            }

            $adminJs = ViteLoader::js('src/ts/admin.ts');

            if ($adminJs !== '') {
                wp_enqueue_script('kk-admin', $adminJs, [], KK_VERSION, true);

                wp_localize_script('kk-admin', 'kkAdminData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('koperasikita_nonce'),
                    'strings' => [
                        'saving' => __('Saving…', 'koperasikita'),
                        'close' => __('Close', 'koperasikita'),
                        'showingOf' => __('Showing %s of %s — type to search.', 'koperasikita'),
                    ],
                ]);
            }
        }

        /* Menus page: the menu item icon picker (MenuIcons) needs both
         * the admin stylesheet (field + popup) and the admin script
         * (popup, search, grid), plus the icon lists for the grid. */
        if ($hookSuffix === 'nav-menus.php') {
            $css = ViteLoader::css('src/ts/admin.ts');

            if ($css !== '') {
                wp_enqueue_style('kk-admin', $css, [], KK_VERSION);
            }

            $js = ViteLoader::js('src/ts/admin.ts');

            if ($js !== '') {
                wp_enqueue_script('kk-admin', $js, [], KK_VERSION, true);

                wp_localize_script('kk-admin', 'kkAdminData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('koperasikita_nonce'),
                    'menuIcons' => [
                        'builtIn' => \KoperasiKita\Icons\Icon::builtInSvgs(20),
                        'noIcon' => __('No icon', 'koperasikita'),
                        'defaultColor' => \KoperasiKita\Setup\MenuIcons::DEFAULT_COLOR,
                    ],
                ]);
            }

            return;
        }

        /* Gold Prices page (Settings -> Gold Prices) and Data Desk
         * Templates page: the manual sync button (admin.ts
         * initGoldSync) and the brands multi-select picker
         * (initTermPickers) need the admin script + ajaxUrl/nonce.
         * Runs before the widgets/customizer early return below,
         * which would drop it. */
        $isGoldPrices = $hookSuffix === AdminMenu::submenuHookSuffix(\KoperasiKita\Admin\GoldPricesPage::PAGE_SLUG);
        $isDataDeskTemplates = $hookSuffix === AdminMenu::submenuHookSuffix(\KoperasiKita\Admin\DataDeskTemplatesPage::PAGE_SLUG);

        if ($isGoldPrices || $isDataDeskTemplates) {
            $css = ViteLoader::css('src/ts/admin.ts');

            if ($css !== '') {
                wp_enqueue_style('kk-admin', $css, [], KK_VERSION);
            }

            // The Data Desk Templates page renders live template
            // previews (the real panel / table markup), so it needs
            // the same styles the editor canvas uses - tokens,
            // widgets, gold-markets and the rest.
            self::enqueueEditorStyles();

            $js = ViteLoader::js('src/ts/admin.ts');

            if ($js !== '') {
                wp_enqueue_script('kk-admin', $js, [], KK_VERSION, true);

                // The manual gold sync progress strings (admin.ts
                // initGoldSync) and the shared admin strings, so the
                // progress lines are translated in the admin language.
                wp_localize_script('kk-admin', 'kkAdminData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('koperasikita_nonce'),
                    'strings' => [
                        'ready' => __('Ready', 'koperasikita'),
                        'failed' => __('Failed: %s', 'koperasikita'),
                        'notDownloaded' => __('Not downloaded yet', 'koperasikita'),
                        'downloadFailed' => __('Download failed.', 'koperasikita'),
                        'adminDataMissing' => __('Admin data is not available.', 'koperasikita'),
                        'syncingSource' => __('Scraping %s…', 'koperasikita'),
                        'syncStepDone' => __('%s: inserted %s, skipped %s', 'koperasikita'),
                        'syncStepLatest' => __(' (latest %s)', 'koperasikita'),
                        'syncDone' => __('Done — inserted %s, skipped %s (already in database). Reloading…', 'koperasikita'),
                        'loadingPreview' => __('Loading preview…', 'koperasikita'),
                        'loadingChart' => __('Loading chart…', 'koperasikita'),
                        'editKickerHint' => __('Click to edit the kicker.', 'koperasikita'),
                        'editTitleHint' => __('Click to edit the title.', 'koperasikita'),
                        'previewFailed' => __('Could not load the preview.', 'koperasikita'),
                        'savingTemplate' => __('Saving…', 'koperasikita'),
                        'editDateLineHint' => __('Click to edit the date line.', 'koperasikita'),
                        'editStatHint' => __('Click to edit this stat.', 'koperasikita'),
                        'editHeaderHint' => __('Click to edit this header.', 'koperasikita'),
                        'close' => __('Close', 'koperasikita'),
                        'showingOf' => __('Showing %s of %s — type to search.', 'koperasikita'),
                    ],
                ]);
            }

            return;
        }

        /* Agenda editor and Locations page: the Pick Location popup
         * (agenda.ts + agenda.css). The branch runs before the
         * widgets/customizer early return below, which would drop it. */
        if (Features::isEnabled('agenda')) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $isAgendaEditor = $screen instanceof \WP_Screen
                && $screen->post_type === \KoperasiKita\Features\Agenda::POST_TYPE
                && in_array($screen->base, ['post', 'post-new'], true);
            $isAgendaLocations = $hookSuffix === AdminMenu::submenuHookSuffix(\KoperasiKita\Features\Agenda::LOCATIONS_PAGE_SLUG);

            if ($isAgendaEditor || $isAgendaLocations) {
                $agendaCss = ViteLoader::css('src/css/agenda.css');

                if ($agendaCss !== '') {
                    wp_enqueue_style('kk-agenda', $agendaCss, [], KK_VERSION);
                }

                /* The Locations page chrome (card, title, buttons,
                 * table) lives in admin.css; the agenda bundle only
                 * carries the map and picker styles. */
                $adminCss = ViteLoader::css('src/ts/admin.ts');

                if ($adminCss !== '') {
                    wp_enqueue_style('kk-admin', $adminCss, [], KK_VERSION);
                }

                $agendaJs = ViteLoader::js('src/ts/agenda.ts');

                if ($agendaJs !== '') {
                    wp_enqueue_script('kk-agenda', $agendaJs, [], KK_VERSION, true);

                    // The inline location search (Event Details box,
                    // Locations form) posts to kk_agenda_place_search
                    // through the shared AJAX envelope, which needs the
                    // shared nonce and the admin-ajax URL.
                    wp_localize_script('kk-agenda', 'kkAgendaData', [
                        'ajaxUrl' => admin_url('admin-ajax.php'),
                        'nonce' => \KoperasiKita\Api\Ajax::nonce(),
                    ]);
                }

                return;
            }
        }

        /* Subscribers page: server-rendered, no admin script, but
         * its card, title, button and table chrome lives in
         * admin.css. */
        $isSubscribers = $hookSuffix === AdminMenu::submenuHookSuffix(\KoperasiKita\Admin\SubscribersPage::PAGE_SLUG);

        if ($isSubscribers) {
            $css = ViteLoader::css('src/ts/admin.ts');

            if ($css !== '') {
                wp_enqueue_style('kk-admin', $css, [], KK_VERSION);
            }

            return;
        }

        if (!$isWidgets && !$isCustomizer) {
            return;
        }

        $css = ViteLoader::css('src/ts/admin.ts');

        if ($css !== '') {
            wp_enqueue_style('kk-admin', $css, [], KK_VERSION);
        }

        // Classic widget forms on widgets.php / the Customizer show
        // a live Data Desk preview under the template select; the
        // preview markup needs the editor bundle's styles here too
        // (the form itself lives in the outer page, not the canvas).
        self::enqueueEditorStyles();

        $js = ViteLoader::js('src/ts/admin.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-admin', $js, [], KK_VERSION, true);

            wp_localize_script('kk-admin', 'kkAdminData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('koperasikita_nonce'),
                // The menu icon picker data: the Agenda widget's header
                // icon reuses the same popup, so widgets.php and the
                // Customizer need the icon list too (not just nav-menus).
                'menuIcons' => [
                    'builtIn' => \KoperasiKita\Icons\Icon::builtInSvgs(20),
                    'noIcon' => __('No icon', 'koperasikita'),
                    'defaultColor' => \KoperasiKita\Setup\MenuIcons::DEFAULT_COLOR,
                ],
                'strings' => [
                    'ready' => __('Ready', 'koperasikita'),
                    'failed' => __('Failed: %s', 'koperasikita'),
                    'notDownloaded' => __('Not downloaded yet', 'koperasikita'),
                    'downloadFailed' => __('Download failed.', 'koperasikita'),
                    'adminDataMissing' => __('Admin data is not available.', 'koperasikita'),
                    'loadingPreview' => __('Loading preview…', 'koperasikita'),
                    'searching' => __('Searching…', 'koperasikita'),
                    'noResults' => __('No results.', 'koperasikita'),
                    'showingOf' => __('Showing %s of %s — type to search.', 'koperasikita'),
                ],
            ]);
        }
    }

    public static function registerCustomizerPreview(): void
    {
        // Same module trap as the admin entry: the preview entry
        // starts with a top-level `import`, and the frontend filter
        // is not registered in the preview frame on its own.
        self::addModuleScriptFilter();

        $js = ViteLoader::js('src/ts/customizer-preview.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-preview', $js, ['customize-preview'], KK_VERSION, true);

            wp_localize_script('kk-customizer-preview', 'kkFontsPreview', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('koperasikita_nonce'),
                'fonts' => Fonts::previewFonts(),
            ]);
        }
    }

    /**
     * Block editor (widgets.php, Customizer widget panel, post
     * editor): the widget blocks render via ServerSideRender, so
     * the preview HTML is the real frontend markup - but the
     * frontend styles are never loaded in the editor canvas, so
     * the widget title (top border, :after rule) renders plain.
     * Load just the tokens (CSS variables, e.g. --kk-ink) and the
     * widget stylesheet so the preview shows the same title
     * styles as the live site. Scoped selectors
     * (.kk-widget-title--*, .kk-widget) only match the preview
     * markup, not the editor chrome.
     *
     * These styles load in the OUTER admin page only - the canvas
     * is an iframe, and `enqueue_block_editor_assets` never enters
     * it. The canvas gets its styles from the blocks' `editor_style`
     * instead: see Blocks::registerAssets(), which registers the
     * editor bundle (src/css/editor.css - tokens, base, content,
     * widgets, gold-markets, weather, variants, plus the canvas
     * shell/title rules) for every Koperasi Kita block. The bundle
     * is enqueued here too, so any block rendered inline in the
     * outer page (widgets.php without an iframe, the Customizer
     * panel) is styled as well.
     */
    /**
     * Enqueue the editor bundle (src/css/editor.css) on an admin
     * screen that renders widget / Data Desk preview markup outside
     * the editor canvas - the Data Desk Templates page and the
     * classic widget forms. The handle is registered by
     * Blocks::registerAssets() for the canvas; wp_enqueue_style
     * on an already-registered handle is enough here.
     */
    private static function enqueueEditorStyles(): void
    {
        $editor = ViteLoader::css('src/css/editor.css');

        if ($editor !== '') {
            wp_enqueue_style('kk-editor', $editor, [], KK_VERSION);
        }
    }

    public static function registerBlockEditor(): void
    {
        $tokens = ViteLoader::css('src/css/tokens.css');

        if ($tokens !== '') {
            wp_enqueue_style('kk-tokens-editor', $tokens, [], KK_VERSION);
        }

        $widgets = ViteLoader::css('src/css/widgets.css');

        if ($widgets !== '') {
            wp_enqueue_style('kk-widgets-editor', $widgets, ['kk-tokens-editor'], KK_VERSION);
        }

        $editor = ViteLoader::css('src/css/editor.css');

        if ($editor !== '') {
            wp_enqueue_style('kk-editor', $editor, ['kk-widgets-editor'], KK_VERSION);
        }
    }
}

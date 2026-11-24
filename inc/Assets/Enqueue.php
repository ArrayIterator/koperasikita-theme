<?php

declare(strict_types=1);

namespace KoperasiKita\Assets;

use KoperasiKita\Features\Features;
use KoperasiKita\Features\Fonts;
use KoperasiKita\Setup\Setup;

final class Enqueue
{
    public static function register(): void
    {
        self::enqueueSplitStyles();

        Fonts::enqueue();

        $js = ViteLoader::js('src/ts/main.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-main', $js, [], KK_VERSION, true);
        }

        if (Features::isEnabled('markets')) {
            $goldCss = ViteLoader::css('src/css/gold-markets.css');

            if ($goldCss !== '') {
                wp_enqueue_style('kk-gold-markets', $goldCss, ['kk-responsive'], KK_VERSION);
            }

            $goldJs = ViteLoader::js('src/ts/gold-markets.ts');

            if ($goldJs !== '') {
                wp_enqueue_script('kk-gold-markets', $goldJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('weather')) {
            $weatherCss = ViteLoader::css('src/css/weather.css');

            if ($weatherCss !== '') {
                wp_enqueue_style('kk-weather', $weatherCss, ['kk-responsive'], KK_VERSION);
            }

            $weatherJs = ViteLoader::js('src/ts/weather.ts');

            if ($weatherJs !== '') {
                wp_enqueue_script('kk-weather', $weatherJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('agenda') && self::needsAgendaAssets()) {
            $agendaCss = ViteLoader::css('src/css/agenda.css');

            if ($agendaCss !== '') {
                wp_enqueue_style('kk-agenda', $agendaCss, ['kk-responsive'], KK_VERSION);
            }

            $agendaJs = ViteLoader::js('src/ts/agenda.ts');

            if ($agendaJs !== '') {
                wp_enqueue_script('kk-agenda', $agendaJs, [], KK_VERSION, true);
            }
        }

        // The text-size control is injected by the `the_content` filter
        // only on singular post/page views, so its script loads only
        // there — nowhere else on the site.
        if (is_singular(['post', 'page'])) {
            $fontSizeJs = ViteLoader::js('src/ts/article-font-size.ts');

            if ($fontSizeJs !== '') {
                wp_enqueue_script('kk-article-font-size', $fontSizeJs, [], KK_VERSION, true);
            }
        }

        // The pagination loader only runs where an archive loop can
        // render it (archive / blog / search) and only when the mode
        // is load-more or infinite — page numbers need no script.
        if ('pagination' !== \KoperasiKita\Features\Pagination::mode() && (is_archive() || is_home() || is_search())) {
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
            'isPwaEnabled' => Features::isEnabled('pwa'),
            'themeUrl' => KK_URI,
        ]);

        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle !== 'kk-main' || str_contains($tag, ' defer')) {
                return $tag;
            }

            return str_replace(' src=', ' defer src=', $tag);
        }, 10, 2);

        Fonts::outputFontVariables();
    }

    /**
     * The split stylesheets, in the exact order of the sections in the
     * old app.css monolith — the cascade depends on it. The shell
     * (reset, tokens, base, header, cards, footer, overlays, variants,
     * responsive) loads on every page. Home-only and content styles
     * load only where their templates can appear.
     */
    private static function enqueueSplitStyles(): void
    {
        self::enqueueStyle('kk-app', 'src/css/app.css', []);
        self::enqueueStyle('kk-tokens', 'src/css/tokens.css', ['kk-app']);
        self::enqueueStyle('kk-base', 'src/css/base.css', ['kk-tokens']);
        self::enqueueStyle('kk-header', 'src/css/header.css', ['kk-base']);

        if (is_front_page()) {
            self::enqueueStyle('kk-home', 'src/css/home.css', ['kk-header']);
        }

        self::enqueueStyle('kk-cards', 'src/css/cards.css', ['kk-header']);

        if (is_front_page()) {
            self::enqueueStyle('kk-home-sections', 'src/css/home-sections.css', ['kk-cards']);
        }

        if (self::needsContentStyles()) {
            self::enqueueStyle('kk-content', 'src/css/content.css', ['kk-cards']);
        }

        self::enqueueStyle('kk-footer', 'src/css/footer.css', ['kk-cards']);
        self::enqueueStyle('kk-overlays', 'src/css/overlays.css', ['kk-footer']);
        self::enqueueStyle('kk-variants', 'src/css/variants.css', ['kk-overlays']);
        self::enqueueStyle('kk-responsive', 'src/css/responsive.css', ['kk-variants']);

        // Newsletter layout styles load on every front-page render, not
        // only when the saved layout is newsletter — the layout
        // switcher is a live client-side control, so the restyle must
        // apply the moment the user clicks Newsletter on a grid/list
        // page. The rules are scoped to .kk-layout-newsletter /
        // .kk-newsletter-*, so they are inert on grid/list pages.
        // Last in the chain (after kk-responsive) so it wins over the
        // card/layout rules it restyles.
        if (is_front_page()) {
            self::enqueueStyle('kk-newsletter', 'src/css/newsletter.css', ['kk-responsive']);
        }

        // Inline styles attached to kk-app (Customizer palette overrides,
        // font variables) must print after the split files, as they did
        // when app.css was one file — otherwise the :root defaults in
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
     * template uses at least one of those — the front page renders the
     * news sidebar, index/archive/search/single render cards plus the
     * sidebar, pages render breadcrumbs, and the bookmarks/404 pages
     * are singular templates — so this is every frontend render.
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
        $isWidgets = str_contains($hookSuffix, 'widgets');
        $isCustomizer = str_contains($hookSuffix, 'customize');
        $isSetup = $hookSuffix === 'appearance_page_' . Setup::PAGE_SLUG;

        if ($isSetup) {
            $setupCss = ViteLoader::css('src/css/setup.css');

            if ($setupCss !== '') {
                wp_enqueue_style('kk-setup', $setupCss, [], KK_VERSION);
            }
        }

        if (!$isWidgets && !$isCustomizer) {
            return;
        }

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
            ]);
        }
    }

    public static function registerCustomizerPreview(): void
    {
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
}

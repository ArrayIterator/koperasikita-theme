<?php

declare(strict_types=1);

namespace KoperasiKita\Assets;

use KoperasiKita\Features\Features;

final class Enqueue
{
    public static function register(): void
    {
        $css = ViteLoader::css('src/css/app.css');

        if ($css !== '') {
            wp_enqueue_style('kk-app', $css, [], KK_VERSION);
        }

        $js = ViteLoader::js('src/ts/main.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-main', $js, [], KK_VERSION, true);
        }

        if (Features::isEnabled('markets')) {
            $goldCss = ViteLoader::css('src/css/gold-markets.css');

            if ($goldCss !== '') {
                wp_enqueue_style('kk-gold-markets', $goldCss, ['kk-app'], KK_VERSION);
            }

            $goldJs = ViteLoader::js('src/ts/gold-markets.ts');

            if ($goldJs !== '') {
                wp_enqueue_script('kk-gold-markets', $goldJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('weather')) {
            $weatherCss = ViteLoader::css('src/css/weather.css');

            if ($weatherCss !== '') {
                wp_enqueue_style('kk-weather', $weatherCss, ['kk-app'], KK_VERSION);
            }

            $weatherJs = ViteLoader::js('src/ts/weather.ts');

            if ($weatherJs !== '') {
                wp_enqueue_script('kk-weather', $weatherJs, [], KK_VERSION, true);
            }
        }

        if (Features::isEnabled('agenda') && self::needsAgendaAssets()) {
            $agendaCss = ViteLoader::css('src/css/agenda.css');

            if ($agendaCss !== '') {
                wp_enqueue_style('kk-agenda', $agendaCss, ['kk-app'], KK_VERSION);
            }

            $agendaJs = ViteLoader::js('src/ts/agenda.ts');

            if ($agendaJs !== '') {
                wp_enqueue_script('kk-agenda', $agendaJs, [], KK_VERSION, true);
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
        }
    }

    public static function registerCustomizerPreview(): void
    {
        $js = ViteLoader::js('src/ts/customizer-preview.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-preview', $js, ['customize-preview'], KK_VERSION, true);
        }
    }
}

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

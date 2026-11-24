<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

final class Performance
{
    public static function register(): void
    {
        if ((bool) get_theme_mod('kk_adv_disable_emoji', false)) {
            self::disableEmoji();
        }

        if ((bool) get_theme_mod('kk_adv_disable_embeds', false)) {
            self::disableEmbeds();
        }

        if ((bool) get_theme_mod('kk_adv_remove_query_strings', false)) {
            add_filter('script_loader_src', [self::class, 'stripVersion'], 15);
            add_filter('style_loader_src', [self::class, 'stripVersion'], 15);
        }

        if ((bool) get_theme_mod('kk_adv_cleanup_head', false)) {
            self::cleanupHead();
        }
    }

    private static function disableEmoji(): void
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        add_filter('tiny_mce_plugins', [self::class, 'removeEmojiPlugin']);
    }

    /** @param array<int, string> $plugins */
    public static function removeEmojiPlugin(array $plugins): array
    {
        return array_values(array_diff($plugins, ['wpemoji']));
    }

    private static function disableEmbeds(): void
    {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        add_filter('embed_oembed_discover', '__return_false');
    }

    public static function stripVersion(string $src): string
    {
        $query = wp_parse_url($src, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return $src;
        }

        parse_str($query, $args);

        if (!isset($args['ver']) || count($args) !== 1) {
            return $src;
        }

        return remove_query_arg('ver', $src);
    }

    private static function cleanupHead(): void
    {
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wp_shortlink_wp_head');
    }
}

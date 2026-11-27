<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Front-end performance and clean-up switches, controlled from the
 * Customizer section "Advanced Performance & Cleanup".
 *
 * Every toggle is a plain on/off checkbox. register() runs on `init`
 * (priority 10, via Features::register()), before wp_head runs and before
 * any asset is printed, so the removals made here still take effect.
 * An OFF toggle registers nothing: it is a complete no-op.
 *
 * Nothing here touches the admin, the Customizer preview, or the PWA:
 * front-end-only switches are guarded with is_admin(), and Dashicons are
 * only removed for logged-out visitors (the admin bar needs them).
 * Disabling XML-RPC does not touch the theme's own REST routes or AJAX -
 * XML-RPC is a separate protocol from the WP REST API and admin-ajax.php.
 */
final class Performance
{
    /**
     * Setting key => [label, default].
     *
     * Single source of truth for the section: the Customizer registers one
     * `kk_adv_<key>` checkbox per entry, and enabled() reads the matching
     * theme mod with the default from this map.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function options(): array
    {
        return [
            'disable_texturize_content' => [__('Disable texturize on content and excerpts', 'koperasikita'), false],
            'disable_texturize_title' => [__('Disable texturize on titles', 'koperasikita'), false],
            'disable_texturize_all' => [__('Disable texturize everywhere (comments, widgets, site name)', 'koperasikita'), false],
            'remove_jquery_migrate' => [__('Remove jQuery Migrate on the front end', 'koperasikita'), true],
            'remove_query_strings' => [__('Remove version query strings from CSS and JS', 'koperasikita'), false],
            'remove_generator' => [__('Remove the WordPress generator tag', 'koperasikita'), true],
            'disable_emoji' => [__('Disable emoji scripts and styles', 'koperasikita'), true],
            'remove_head_links' => [__('Remove RSD, WLW, shortlink and REST discovery links from the head', 'koperasikita'), true],
            'disable_embeds' => [__('Disable oEmbed discovery and the embed script', 'koperasikita'), true],
            'remove_block_styles' => [__('Remove block library and global styles CSS (classic widgets only)', 'koperasikita'), false],
            'disable_dashicons' => [__('Remove Dashicons for visitors', 'koperasikita'), false],
            'disable_heartbeat' => [__('Disable the Heartbeat script on the front end', 'koperasikita'), false],
            'disable_xmlrpc' => [__('Disable XML-RPC', 'koperasikita'), false],
            'disable_self_pingbacks' => [__('Disable self pingbacks', 'koperasikita'), false],
        ];
    }

    public static function enabled(string $key): bool
    {
        $options = self::options();
        $default = $options[$key][1] ?? false;

        return (bool) get_theme_mod('kk_adv_' . $key, $default);
    }

    public static function register(): void
    {
        self::applyTexturize();
        self::applyGenerator();
        self::applyEmoji();
        self::applyHeadLinks();
        self::applyEmbeds();
        self::applyXmlrpc();
        self::applyQueryStrings();
        self::applyJqueryMigrate();
        self::applyAssets();
        self::applySelfPingbacks();
    }

    /**
     * wptexturize switches. The "all" switch is a global kill switch and
     * takes precedence over the two granular ones.
     */
    private static function applyTexturize(): void
    {
        if (self::enabled('disable_texturize_all')) {
            add_filter('run_wptexturize', '__return_false');

            return;
        }

        if (self::enabled('disable_texturize_content')) {
            foreach (['the_content', 'the_excerpt', 'widget_text_content', 'widget_block_content'] as $hook) {
                remove_filter($hook, 'wptexturize');
            }
        }

        if (self::enabled('disable_texturize_title')) {
            foreach (['the_title', 'single_post_title', 'wp_title', 'document_title_parts'] as $hook) {
                remove_filter($hook, 'wptexturize');
            }
            // Without wptexturize, titles can carry raw entities (e.g. &#039;)
            // that it used to normalise; decode them so titles read cleanly.
            add_filter('document_title_parts', [self::class, 'decodeTitleParts'], 20);
        }
    }

    /** @param array<string, mixed> $parts @return array<string, mixed> */
    public static function decodeTitleParts(array $parts): array
    {
        return array_map(
            static fn ($part) => is_string($part) ? html_entity_decode($part, ENT_QUOTES, 'UTF-8') : $part,
            $parts
        );
    }

    private static function applyGenerator(): void
    {
        if (!self::enabled('remove_generator')) {
            return;
        }

        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
    }

    private static function applyEmoji(): void
    {
        if (!self::enabled('disable_emoji')) {
            return;
        }

        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('tiny_mce_plugins', [self::class, 'removeEmojiPlugin']);
        add_filter('wp_resource_hints', [self::class, 'removeEmojiHint'], 10, 2);
    }

    /** @param array<int, string> $plugins */
    public static function removeEmojiPlugin(array $plugins): array
    {
        return array_values(array_diff($plugins, ['wpemoji']));
    }

    /** @param array<int, mixed> $urls @return array<int, mixed> */
    public static function removeEmojiHint(array $urls, string $relationType): array
    {
        if ($relationType !== 'dns-prefetch') {
            return $urls;
        }

        return array_filter(
            $urls,
            static fn ($url): bool => !is_string($url) || !str_contains($url, 's.w.org')
        );
    }

    private static function applyHeadLinks(): void
    {
        if (!self::enabled('remove_head_links')) {
            return;
        }

        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('template_redirect', 'rest_output_link_header', 11);
    }

    private static function applyEmbeds(): void
    {
        if (!self::enabled('disable_embeds')) {
            return;
        }

        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        add_filter('embed_oembed_discover', '__return_false');
        // The wp-embed script itself is dequeued with the front-end assets.
    }

    private static function applyXmlrpc(): void
    {
        if (!self::enabled('disable_xmlrpc')) {
            return;
        }

        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_headers', [self::class, 'removePingbackHeader']);
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function removePingbackHeader(array $headers): array
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    private static function applyQueryStrings(): void
    {
        if (!self::enabled('remove_query_strings')) {
            return;
        }

        add_filter('script_loader_src', [self::class, 'stripVersion'], 15);
        add_filter('style_loader_src', [self::class, 'stripVersion'], 15);
    }

    /**
     * Strip the `ver` query argument from an asset URL, but only when it is
     * the only query argument. A URL carrying other parameters is left
     * alone: removing `ver` from it could change what the server returns.
     */
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

    private static function applyJqueryMigrate(): void
    {
        if (!self::enabled('remove_jquery_migrate')) {
            return;
        }

        add_filter('wp_default_scripts', [self::class, 'removeJqueryMigrate']);
    }

    /**
     * Drop jquery-migrate from jquery's dependencies. The admin is exempt:
     * older plugins running there still rely on it.
     */
    public static function removeJqueryMigrate(\WP_Scripts $scripts): void
    {
        if (is_admin() || !isset($scripts->registered['jquery'])) {
            return;
        }

        $jquery = $scripts->registered['jquery'];
        if (is_array($jquery->deps)) {
            $jquery->deps = array_values(array_diff($jquery->deps, ['jquery-migrate']));
        }
    }

    private static function applyAssets(): void
    {
        if (!self::enabled('remove_block_styles')
            && !self::enabled('disable_dashicons')
            && !self::enabled('disable_heartbeat')
            && !self::enabled('disable_embeds')
        ) {
            return;
        }

        add_action('wp_enqueue_scripts', [self::class, 'dequeueAssets'], 100);
        add_action('wp_print_styles', [self::class, 'dequeueAssets'], 100);
    }

    /**
     * Front-end asset clean-up. Runs late (priority 100), after everything
     * is enqueued, and gates on each setting inside, so an OFF toggle is a
     * no-op. Never runs in the admin.
     */
    public static function dequeueAssets(): void
    {
        if (is_admin()) {
            return;
        }

        if (self::enabled('remove_block_styles')) {
            foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles'] as $handle) {
                wp_dequeue_style($handle);
            }
        }

        if (self::enabled('disable_dashicons') && !is_user_logged_in()) {
            wp_dequeue_style('dashicons');
        }

        if (self::enabled('disable_heartbeat')) {
            wp_deregister_script('heartbeat');
        }

        if (self::enabled('disable_embeds')) {
            wp_dequeue_script('wp-embed');
        }
    }

    private static function applySelfPingbacks(): void
    {
        if (!self::enabled('disable_self_pingbacks')) {
            return;
        }

        add_action('pre_ping', [self::class, 'removeSelfPings']);
    }

    /** @param string[] $links */
    public static function removeSelfPings(array &$links): void
    {
        $home = home_url();

        foreach ($links as $index => $link) {
            if (str_starts_with($link, $home)) {
                unset($links[$index]);
            }
        }
    }
}

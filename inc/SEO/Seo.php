<?php

declare(strict_types=1);

namespace KoperasiKita\SEO;

final class Seo
{
    public static function register(): void
    {
        add_action('wp_head', [self::class, 'outputMeta'], 5);
        add_action('wp_head', [self::class, 'outputJsonLd'], 6);
    }

    public static function outputMeta(): void
    {
        $title = wp_get_document_title();
        $description = self::description();
        $url = self::currentUrl();
        $image = self::image();
        $type = is_single() ? 'article' : 'website';

        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";

        if ($image !== '') {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
    }

    public static function outputJsonLd(): void
    {
        if (!is_single()) {
            return;
        }

        $post = get_post();

        if ($post === null) {
            return;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => get_the_title($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', (int) $post->post_author),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
            ],
        ];

        $image = self::image();

        if ($image !== '') {
            $data['image'] = $image;
        }

        echo '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
    }

    private static function description(): string
    {
        if (is_single()) {
            $excerpt = get_the_excerpt();

            if ($excerpt !== '') {
                return wp_strip_all_tags($excerpt);
            }
        }

        return (string) get_bloginfo('description');
    }

    private static function currentUrl(): string
    {
        if (is_single() || is_page()) {
            return (string) get_permalink();
        }

        return home_url(add_query_arg([]));
    }

    private static function image(): string
    {
        if (is_single() && has_post_thumbnail()) {
            $url = get_the_post_thumbnail_url(null, 'full');

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $logoId = get_theme_mod('custom_logo');

        if (is_numeric($logoId)) {
            $url = wp_get_attachment_image_url((int) $logoId, 'full');

            if (is_string($url)) {
                return $url;
            }
        }

        return '';
    }
}

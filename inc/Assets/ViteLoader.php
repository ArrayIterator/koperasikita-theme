<?php

declare(strict_types=1);

namespace KoperasiKita\Assets;

final class ViteLoader
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        self::$manifest = [];
        $path = KK_DIR . '/dist/.vite/manifest.json';

        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                self::$manifest = $decoded;
            }
        }

        return self::$manifest;
    }

    private static function devUrl(string $entry): string
    {
        return 'http://localhost:5173/' . ltrim($entry, '/');
    }

    public static function assetUrl(string $entry): string
    {
        $manifest = self::manifest();

        if (isset($manifest[$entry]['file'])) {
            return KK_URI . '/dist/' . (string) $manifest[$entry]['file'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::devUrl($entry);
        }

        return '';
    }

    public static function css(string $entry): string
    {
        $manifest = self::manifest();

        if (isset($manifest[$entry]['css'][0])) {
            return KK_URI . '/dist/' . (string) $manifest[$entry]['css'][0];
        }

        if (isset($manifest[$entry]['file']) && str_ends_with((string) $manifest[$entry]['file'], '.css')) {
            return KK_URI . '/dist/' . (string) $manifest[$entry]['file'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::devUrl($entry);
        }

        return '';
    }

    public static function js(string $entry): string
    {
        $manifest = self::manifest();

        if (isset($manifest[$entry]['file'])) {
            return KK_URI . '/dist/' . (string) $manifest[$entry]['file'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::devUrl($entry);
        }

        return '';
    }
}

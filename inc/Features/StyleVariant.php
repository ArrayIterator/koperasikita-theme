<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

final class StyleVariant
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return ['classic', 'compact', 'bold'];
    }

    public static function current(): string
    {
        $value = (string) get_theme_mod('kk_style_variant', 'classic');

        return in_array($value, self::all(), true) ? $value : 'classic';
    }

    public static function register(): void
    {
        add_filter('body_class', static function (array $classes): array {
            $classes[] = 'kk-variant-' . self::current();

            if (!(bool) get_theme_mod('kk_sticky_header', true)) {
                $classes[] = 'kk-sticky-off';
            }

            return $classes;
        });
    }
}

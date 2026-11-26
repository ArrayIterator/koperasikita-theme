<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Hero Presentation modes: how the hero shows its stories, and
 * whether a widget area sits beside it.
 *
 * Grid modes show a lead story plus cards; slider modes show one
 * story at a time. The widget modes pair the hero with the Hero
 * Left / Hero Right widget areas (Appearance -> Widgets).
 */
final class Hero
{
    public const GRID_LEFT = 'grid-left';
    public const GRID_RIGHT = 'grid-right';
    public const GRID_CENTER = 'grid-center';
    public const GRID_LEFT_WIDGET = 'grid-left-widget';
    public const GRID_RIGHT_WIDGET = 'grid-right-widget';
    public const LEADS_WIDGETS = 'leads-widgets';
    public const SLIDER_ONE_LEFT_WIDGET = 'slider-one-left-widget';
    public const SLIDER_ONE_RIGHT_WIDGET = 'slider-one-right-widget';
    public const SLIDER_FULL = 'slider-full';
    public const SLIDER_CENTER = 'slider-center';
    public const SLIDER_LEFT_WIDGET = 'slider-left-widget';
    public const SLIDER_RIGHT_WIDGET = 'slider-right-widget';

    /**
     * @return array<string, string> Slug => English label.
     */
    public static function modes(): array
    {
        return [
            self::GRID_LEFT => 'Grid Left (Leads + Cards)',
            self::GRID_RIGHT => 'Grid Right (Leads + Cards)',
            self::GRID_CENTER => 'Grid Center (Leads + Cards)',
            self::GRID_LEFT_WIDGET => 'Grid Left Widget (Leads + Widget)',
            self::GRID_RIGHT_WIDGET => 'Grid Right Widget (Widget + Leads)',
            self::LEADS_WIDGETS => 'Leads Widgets (Widget + Leads + Widget)',
            self::SLIDER_ONE_LEFT_WIDGET => 'Slider One Left With Right Widget',
            self::SLIDER_ONE_RIGHT_WIDGET => 'Slider One Right With Left Widget',
            self::SLIDER_FULL => 'Slider Full (Full Slider)',
            self::SLIDER_CENTER => 'Slider Center',
            self::SLIDER_LEFT_WIDGET => 'Slider Left Widget',
            self::SLIDER_RIGHT_WIDGET => 'Slider Right Widget',
        ];
    }

    public static function sanitize(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return array_key_exists($value, self::modes()) ? $value : self::GRID_LEFT;
    }

    public static function isSlider(string $mode): bool
    {
        return str_starts_with($mode, 'slider');
    }

    public static function hasLeftWidget(string $mode): bool
    {
        return in_array($mode, [
            self::GRID_RIGHT_WIDGET,
            self::LEADS_WIDGETS,
            self::SLIDER_ONE_RIGHT_WIDGET,
            self::SLIDER_CENTER,
            self::SLIDER_LEFT_WIDGET,
        ], true);
    }

    public static function hasRightWidget(string $mode): bool
    {
        return in_array($mode, [
            self::GRID_LEFT_WIDGET,
            self::LEADS_WIDGETS,
            self::SLIDER_ONE_LEFT_WIDGET,
            self::SLIDER_CENTER,
            self::SLIDER_RIGHT_WIDGET,
        ], true);
    }
}

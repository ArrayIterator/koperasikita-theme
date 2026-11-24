<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

use KoperasiKita\Features\Features;
use KoperasiKita\Widgets\GoldWidget;
use KoperasiKita\Widgets\MarketsWidget;
use KoperasiKita\Widgets\WeatherWidget;

final class Widgets
{
    public static function register(): void
    {
        // A disabled feature registers nothing: its widget never appears
        // in the admin, so there is no settings link for a feature that
        // is off.
        if (Features::isEnabled('markets')) {
            register_widget(GoldWidget::class);
            register_widget(MarketsWidget::class);
        }

        if (Features::isEnabled('weather')) {
            register_widget(WeatherWidget::class);
        }
    }
}

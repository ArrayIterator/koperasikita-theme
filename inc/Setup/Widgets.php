<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

use KoperasiKita\Features\Features;
use KoperasiKita\Widgets\GoldWidget;
use KoperasiKita\Widgets\MarketsWidget;

final class Widgets
{
    public static function register(): void
    {
        // A disabled feature registers nothing: its widget never appears
        // in the admin, so there is no settings link for a feature that
        // is off. (WeatherWidget is registered here too, once Phase 3
        // wires the Weather module.)
        if (Features::isEnabled('markets')) {
            register_widget(GoldWidget::class);
            register_widget(MarketsWidget::class);
        }
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

use KoperasiKita\Features\Features;
use KoperasiKita\Widgets\EditorsPicksWidget;
use KoperasiKita\Widgets\GoldWidget;
use KoperasiKita\Widgets\MarketsWidget;
use KoperasiKita\Widgets\PagesWidget;
use KoperasiKita\Widgets\PopularPostsWidget;
use KoperasiKita\Widgets\RecentPostsWidget;
use KoperasiKita\Widgets\RelatedPostsWidget;
use KoperasiKita\Widgets\WeatherWidget;
use KoperasiKita\Widgets\YahooFinanceWidget;

final class Widgets
{
    public static function register(): void
    {
        // The story widgets and the pages list have no feature
        // behind them, so they always register.
        register_widget(RecentPostsWidget::class);
        register_widget(PopularPostsWidget::class);
        register_widget(RelatedPostsWidget::class);
        register_widget(EditorsPicksWidget::class);
        register_widget(PagesWidget::class);

        // A disabled feature registers nothing: its widget never appears
        // in the admin, so there is no settings link for a feature that
        // is off.
        if (Features::isEnabled('markets')) {
            register_widget(GoldWidget::class);
            register_widget(MarketsWidget::class);
            register_widget(YahooFinanceWidget::class);
        }

        if (Features::isEnabled('weather')) {
            register_widget(WeatherWidget::class);
        }
    }
}

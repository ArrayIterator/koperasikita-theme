<?php

declare(strict_types=1);

namespace KoperasiKita;

use KoperasiKita\Api\Api;
use KoperasiKita\Assets\Enqueue;
use KoperasiKita\Breadcrumb\Breadcrumb;
use KoperasiKita\Customizer\Customizer;
use KoperasiKita\Features\Features;
use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Pwa;
use KoperasiKita\Features\StyleVariant;
use KoperasiKita\SEO\Seo;
use KoperasiKita\Setup\Setup;
use KoperasiKita\Setup\Sidebars;
use KoperasiKita\Setup\ThemeSupport;
use KoperasiKita\Setup\Widgets;

final class Theme
{
    public const VERSION = '1.0.0';

    public static function boot(): void
    {
        if (!defined('KK_VERSION')) {
            define('KK_VERSION', self::VERSION);
        }

        if (!defined('KK_API_VERSION')) {
            define('KK_API_VERSION', Api::VERSION);
        }

        if (!defined('KK_DIR')) {
            define('KK_DIR', get_template_directory());
        }

        if (!defined('KK_URI')) {
            define('KK_URI', get_template_directory_uri());
        }

        add_action('after_setup_theme', [ThemeSupport::class, 'register']);

        add_action('widgets_init', [Sidebars::class, 'register']);
        add_action('widgets_init', [Widgets::class, 'register']);

        add_action('customize_register', [Customizer::class, 'register']);

        add_action('wp_enqueue_scripts', [Enqueue::class, 'register']);
        add_action('admin_enqueue_scripts', [Enqueue::class, 'registerAdmin']);
        add_action('customize_preview_init', [Enqueue::class, 'registerCustomizerPreview']);
        add_action('wp_enqueue_scripts', [Customizer::class, 'outputPaletteOverrides'], 20);
        add_filter('get_custom_logo', [Customizer::class, 'filterCustomLogo']);

        add_action('init', [Features::class, 'register']);
        add_action('init', [Fonts::class, 'register']);
        add_action('init', [Api::class, 'register']);
        add_action('init', [StyleVariant::class, 'register']);
        add_action('init', [Pwa::class, 'register']);
        add_action('init', [Seo::class, 'register']);
        add_action('init', [Breadcrumb::class, 'register']);

        add_action('admin_menu', [Setup::class, 'register']);
        add_action('admin_notices', [Setup::class, 'adminNotice']);
        add_action('admin_post_kk_setup_install', [Setup::class, 'handleInstall']);
        add_action('admin_post_kk_setup_upload', [Setup::class, 'handleUpload']);
        add_action('admin_post_kk_setup_activate', [Setup::class, 'handleActivate']);
    }
}

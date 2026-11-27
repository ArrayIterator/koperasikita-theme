<?php

declare(strict_types=1);

namespace KoperasiKita;

use KoperasiKita\Admin\AdminMenu;
use KoperasiKita\Admin\SidebarSettings;
use KoperasiKita\Admin\SubscribersPage;
use KoperasiKita\Api\Api;
use KoperasiKita\Assets\Enqueue;
use KoperasiKita\Breadcrumb\Breadcrumb;
use KoperasiKita\Customizer\Customizer;
use KoperasiKita\Customizer\TermSearch;
use KoperasiKita\Features\Features;
use KoperasiKita\Features\Fonts;
use KoperasiKita\Features\Logo;
use KoperasiKita\Features\Pwa;
use KoperasiKita\Features\StyleVariant;
use KoperasiKita\SEO\Seo;
use KoperasiKita\Setup\MenuIcons;
use KoperasiKita\Setup\Menus;
use KoperasiKita\Setup\Setup;
use KoperasiKita\Setup\Sidebars;
use KoperasiKita\Setup\ThemeSupport;
use KoperasiKita\Setup\Widgets;
use KoperasiKita\Widgets\Blocks;

final class Theme
{
    public const VERSION = '1.0.1';

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
        add_action('customize_register', [Customizer::class, 'registerLogoControls'], 20);
        add_action('customize_register', [Customizer::class, 'registerAdvancedLogoControls'], 20);

        add_action('wp_enqueue_scripts', [Enqueue::class, 'register']);
        add_action('admin_enqueue_scripts', [Enqueue::class, 'registerAdmin']);
        add_action('customize_preview_init', [Enqueue::class, 'registerCustomizerPreview']);
        add_action('enqueue_block_editor_assets', [Enqueue::class, 'registerBlockEditor']);
        add_action('enqueue_block_editor_assets', [Enqueue::class, 'dequeueCanvasBundleFromOuterPage'], 20);
        add_action('wp_enqueue_scripts', [Customizer::class, 'outputPaletteOverrides'], 20);
        add_action('wp_enqueue_scripts', [Customizer::class, 'outputTypographySizeOverrides'], 20);
        add_action('wp_enqueue_scripts', [Customizer::class, 'outputClampOverrides'], 20);
        add_action('wp_enqueue_scripts', [Customizer::class, 'outputMobileFontSizeOverride'], 20);
        add_filter('get_custom_logo', [Customizer::class, 'filterCustomLogo']);
        add_filter('excerpt_length', [Customizer::class, 'filterExcerptLength'], 999);
        add_filter('excerpt_more', [Customizer::class, 'filterExcerptMore'], 999);

        add_action('init', [Features::class, 'register']);
        add_action('init', [Fonts::class, 'register']);
        add_action('init', [Api::class, 'register']);
        add_action('init', [TermSearch::class, 'register']);
        add_action('init', [StyleVariant::class, 'register']);
        add_action('init', [SidebarSettings::class, 'register']);
        add_action('init', [Pwa::class, 'register']);
        add_action('init', [Seo::class, 'register']);
        add_action('init', [Breadcrumb::class, 'register']);
        add_action('init', [Blocks::class, 'register']);
        add_action('init', [MenuIcons::class, 'register']);
        add_action('init', [Menus::class, 'register']);
        add_action('init', [Logo::class, 'register']);

        add_action('init', [AdminMenu::class, 'register']);
        add_action('admin_notices', [Setup::class, 'adminNotice']);
        add_action('admin_post_kk_setup_install', [Setup::class, 'handleInstall']);
        add_action('admin_post_kk_setup_upload', [Setup::class, 'handleUpload']);
        add_action('admin_post_kk_setup_activate', [Setup::class, 'handleActivate']);
        add_action('admin_post_kk_fast_search_save', [Setup::class, 'handleFastSearchSave']);
        add_action('admin_post_kk_export_subscribers', [SubscribersPage::class, 'handleExport']);
        add_action('admin_post_kk_delete_subscriber', [SubscribersPage::class, 'handleDelete']);
    }
}

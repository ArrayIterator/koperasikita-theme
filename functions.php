<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (PHP_VERSION_ID < 80300) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('The Koperasi Kita theme requires PHP 8.3 or higher.', 'koperasikita')
            . '</p></div>';
    });

    return;
}

$kkAutoload = get_template_directory() . '/vendor/autoload.php';

if (is_readable($kkAutoload)) {
    require $kkAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'KoperasiKita\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = get_template_directory() . '/inc/' . $relative . '.php';

        if (is_readable($file)) {
            require $file;
        }
    });
}

// The TrayDigita Stack plugin is optional. Without it the theme still loads;
// only the Stack-backed integrations are unavailable. The admin notice
// pointing at the Setup page lives in KoperasiKita\Setup\Setup::adminNotice().
KoperasiKita\Theme::boot();

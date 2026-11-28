<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

/**
 * Setup admin page (Appearance -> Koperasi Kita Setup).
 *
 * The page shows the theme's requirements checklist, installs the
 * TrayDigita Stack companion plugin from its latest GitHub release
 * (with an upload-.zip fallback), and links the quick-start tasks.
 *
 * The Stack is optional: the theme boots without it. This page only
 * makes installing it a one-click job instead of a dead-end notice.
 */
final class Setup
{
    public const PAGE_SLUG = 'koperasikita';

    public const PLUGIN_FILE = 'traydigita-stack/traydigita-stack.php';

    public const PLUGIN_SLUG = 'traydigita-stack';

    public const RELEASES_API = 'https://api.github.com/repos/ArrayIterator/traydigita-stack/releases/latest';

    public const LOCK_TRANSIENT = 'kk_setup_stack_lock';

    public const ERROR_TRANSIENT = 'kk_setup_last_error';

    public const LOCK_TTL = 300;

    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /** @var array<int, string> */
    private const STATUS_KEYS = [
        'installed',
        'activated',
        'already_active',
        'locked',
        'no_direct_fs',
        'invalid_zip',
        'invalid_url',
        'no_asset',
        'api_failed',
        'install_failed',
        'upload_failed',
        'activate_failed',
        'fast_search_saved',
        'fast_search_save_failed',
    ];

    public static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    /**
     * Replaces the old plain functions.php notice: when the Stack is not
     * active, point the admin at the Setup page instead of a dead end.
     * When the Stack is active there is nothing to say at all.
     */
    public static function adminNotice(): void
    {
        if (!current_user_can('install_plugins')) {
            return;
        }

        if (class_exists('TrayDigita\\WP\\Stack\\Source\\Stack')) {
            return;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();

            if ($screen !== null && $screen->id === 'toplevel_page_' . self::PAGE_SLUG) {
                return;
            }
        }

        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__(
                'The TrayDigita Stack plugin is not active. Some integrations are unavailable.',
                'koperasikita'
            )
            . ' <a href="' . esc_url(self::pageUrl()) . '">'
            . esc_html__('Install it from Setup', 'koperasikita')
            . '</a></p></div>';
    }

    public static function render(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'koperasikita'));
        }

        $status = isset($_GET['kk_setup_status']) ? sanitize_key((string) wp_unslash($_GET['kk_setup_status'])) : '';
        $status = in_array($status, self::STATUS_KEYS, true) ? $status : '';
        $detail = get_transient(self::ERROR_TRANSIENT);

        if ($detail !== false) {
            delete_transient(self::ERROR_TRANSIENT);
        }

        $stackStatus = self::stackStatus();
        $checks = self::checks($stackStatus);
        $bookmarksUrl = self::bookmarksPageUrl();

        echo '<div class="wrap kk-setup">';
        echo '<header class="kk-setup-header">';
        echo '<img class="kk-setup-logo" src="' . esc_url(KK_URI . '/assets/img/logo-k.svg') . '" alt="' . esc_attr((string) get_bloginfo('name')) . '" width="64" height="64">';
        echo '<div><h1 class="kk-setup-title">' . esc_html__('Koperasi Kita Setup', 'koperasikita') . '</h1>';
        echo '<p class="kk-setup-subtitle">' . esc_html__('Everything the theme needs, in one place.', 'koperasikita') . '</p></div>';
        echo '</header>';

        if ($status !== '') {
            [$kind, $message] = self::statusMessage($status);
            echo '<div class="kk-setup-notice kk-setup-notice--' . esc_attr($kind) . '"><p>'
                . esc_html($message)
                . ($detail !== false && $detail !== '' ? ' ' . esc_html((string) $detail) : '')
                . '</p></div>';
        }

        echo '<section class="kk-setup-card">';
        echo '<h2 class="kk-setup-card-title">' . esc_html__('Requirements', 'koperasikita') . '</h2>';
        echo '<div class="kk-setup-card-body"><ul class="kk-setup-checks">';

        foreach ($checks as $check) {
            $pill = $check['state'] === 'pass'
                ? __('Ready', 'koperasikita')
                : ($check['state'] === 'warn' ? __('Attention', 'koperasikita') : __('Missing', 'koperasikita'));

            echo '<li class="kk-setup-check">';
            echo '<span class="kk-setup-check-name">' . esc_html($check['name']) . '</span>';
            echo '<span class="kk-setup-check-detail">' . esc_html($check['detail']) . '</span>';
            echo '<span class="kk-setup-pill kk-setup-pill--' . esc_attr($check['state']) . '">' . esc_html($pill) . '</span>';
            echo '</li>';
        }

        echo '</ul></div></section>';

        echo '<section class="kk-setup-card kk-setup-card--stack">';
        echo '<h2 class="kk-setup-card-title">' . esc_html__('TrayDigita Stack', 'koperasikita') . '</h2>';
        echo '<div class="kk-setup-card-body">';
        echo '<p class="kk-setup-stack-desc">' . esc_html__(
            'The Stack is the theme’s companion plugin. It provides the image, popular-posts and messaging helpers. The theme works without it, but those helpers fall back to basic behaviour.',
            'koperasikita'
        ) . '</p>';

        if ($stackStatus === 'active') {
            echo '<p class="kk-setup-stack-status">' . esc_html__(
                'The Stack is installed and active. All integrations are available.',
                'koperasikita'
            ) . '</p>';
        } elseif ($stackStatus === 'installed') {
            echo '<p class="kk-setup-stack-status">' . esc_html__(
                'The Stack is installed but not active.',
                'koperasikita'
            ) . '</p>';
            echo '<form class="kk-setup-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="kk_setup_activate">';
            wp_nonce_field('kk_setup_activate');
            echo '<button class="kk-setup-button kk-setup-button--primary" type="submit">'
                . esc_html__('Activate TrayDigita Stack', 'koperasikita')
                . '</button></form>';
        } else {
            echo '<p class="kk-setup-stack-status">' . esc_html__(
                'The Stack is not installed yet.',
                'koperasikita'
            ) . '</p>';
            echo '<form class="kk-setup-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="kk_setup_install">';
            wp_nonce_field('kk_setup_install');
            echo '<button class="kk-setup-button kk-setup-button--primary" type="submit">'
                . esc_html__('Install latest release from GitHub', 'koperasikita')
                . '</button></form>';
            echo '<form class="kk-setup-form kk-setup-form--upload" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="action" value="kk_setup_upload">';
            wp_nonce_field('kk_setup_upload');
            echo '<label class="kk-setup-file-label" for="kk-stack-zip">' . esc_html__(
                'Or upload a release zip (max 20 MB):',
                'koperasikita'
            ) . '</label>';
            echo '<input class="kk-setup-file" id="kk-stack-zip" type="file" name="kk_stack_zip" accept=".zip" required>';
            echo '<button class="kk-setup-button" type="submit">'
                . esc_html__('Upload & install', 'koperasikita')
                . '</button></form>';
        }

        echo '</div></section>';

        echo '<section class="kk-setup-card">';
        echo '<h2 class="kk-setup-card-title">' . esc_html__('Quick start', 'koperasikita') . '</h2>';
        echo '<div class="kk-setup-card-body"><div class="kk-setup-links">';
        echo '<a class="kk-setup-link" href="' . esc_url(admin_url('customize.php?autofocus[panel]=koperasikita_theme_options')) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Theme options', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('Logo, colours, layout and typography, with live preview.', 'koperasikita') . '</span></a>';
        echo '<a class="kk-setup-link" href="' . esc_url($bookmarksUrl) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Bookmarks', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('The saved-articles page. Bookmarks live in the browser, per reader.', 'koperasikita') . '</span></a>';
        echo '<a class="kk-setup-link" href="' . esc_url(admin_url('nav-menus.php')) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Menus', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('Primary menu and the mobile bottom navigation.', 'koperasikita') . '</span></a>';
        echo '<a class="kk-setup-link" href="' . esc_url(admin_url('widgets.php')) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Widgets', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('Gold, markets and weather widgets for the sidebars.', 'koperasikita') . '</span></a>';
        echo '<a class="kk-setup-link" data-kk-sidebar-settings-popup href="' . esc_url(admin_url('admin.php?page=' . \KoperasiKita\Admin\SidebarSettings::PAGE_SLUG)) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Sidebar Settings', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('Sticky sidebars and which sidebar shows where.', 'koperasikita') . '</span></a>';
        echo '<a class="kk-setup-link" href="' . esc_url(admin_url('admin.php?page=' . \KoperasiKita\Features\Agenda::LOCATIONS_PAGE_SLUG)) . '">';
        echo '<span class="kk-setup-link-title">' . esc_html__('Locations', 'koperasikita') . '</span>';
        echo '<span class="kk-setup-link-desc">' . esc_html__('Agenda locations with map coordinates.', 'koperasikita') . '</span></a>';
        echo '</div></div></section>';

        \KoperasiKita\Features\FastSearch\FastSearch::renderSetupCard();

        echo '<p class="kk-setup-footnote">' . esc_html__('Koperasi Kita theme', 'koperasikita')
            . ' ' . esc_html(KK_VERSION) . '</p>';

        self::renderSidebarPopup();

        echo '</div>';
    }

    /**
     * The Sidebar Settings popup: the same eight sticky checkboxes as
     * the full settings page, server-rendered with the current
     * values, saved through kk_sidebar_settings_save (admin.ts).
     * The trigger link keeps its href to the full page as the
     * no-JS fallback; the popup only ever opens through JS.
     */
    private static function renderSidebarPopup(): void
    {
        ?>
        <div class="kk-sidebar-popup" id="kk-sidebar-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-sidebar-popup-title">
            <div class="kk-sidebar-popup-backdrop" data-kk-sidebar-popup-close></div>
            <div class="kk-sidebar-popup-panel" role="document">
                <div class="kk-sidebar-popup-head">
                    <h2 id="kk-sidebar-popup-title"><?php esc_html_e('Sidebar Settings', 'koperasikita'); ?></h2>
                    <div class="kk-sidebar-popup-actions">
                        <button type="button" class="button" data-kk-sidebar-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <p class="description"><?php esc_html_e('Choose which sidebars stay visible while the reader scrolls. Only sidebars can stick — the full-width widget areas (Below Header, Before Latest News, Before Highlights, Block Area) always scroll with the page.', 'koperasikita'); ?></p>
                <form id="kk-sidebar-popup-form">
                    <ul class="kk-sidebar-popup-list">
                        <?php foreach (\KoperasiKita\Admin\SidebarSettings::stickySidebars() as $id => [$name, $description]) : ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(\KoperasiKita\Admin\SidebarSettings::OPTION); ?>[sticky][<?php echo esc_attr($id); ?>]" value="1" data-kk-sidebar-id="<?php echo esc_attr($id); ?>"<?php checked(\KoperasiKita\Admin\SidebarSettings::isSticky($id)); ?>>
                                    <?php echo esc_html($name); ?>
                                </label>
                                <p class="description"><?php echo esc_html($description); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save settings', 'koperasikita'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handleInstall(): void
    {
        check_admin_referer('kk_setup_install');

        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to install plugins.', 'koperasikita'));
        }

        if (self::stackStatus() === 'active') {
            self::redirectWithStatus('already_active');
        }

        if (get_transient(self::LOCK_TRANSIENT) !== false) {
            self::redirectWithStatus('locked');
        }

        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

        try {
            $asset = self::latestReleaseAsset();

            if (is_wp_error($asset)) {
                /** @var \WP_Error $asset */
                self::rememberError($asset->get_error_message());
                $code = $asset->get_error_code();
                $status = in_array($code, ['api_failed', 'no_asset', 'invalid_url'], true) ? $code : 'api_failed';
                self::redirectWithStatus($status);
            }

            /** @var array{url: string, version: string} $asset */
            $error = self::installFromUrl($asset['url']);

            if ($error !== null) {
                self::rememberError($error);
                self::redirectWithStatus('install_failed');
            }
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }

        self::redirectWithStatus('installed');
    }

    public static function handleUpload(): void
    {
        check_admin_referer('kk_setup_upload');

        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to install plugins.', 'koperasikita'));
        }

        if (self::stackStatus() === 'active') {
            self::redirectWithStatus('already_active');
        }

        if (get_transient(self::LOCK_TRANSIENT) !== false) {
            self::redirectWithStatus('locked');
        }

        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

        $uploadedFile = null;

        try {
            if (!isset($_FILES['kk_stack_zip']) || !is_array($_FILES['kk_stack_zip'])) {
                self::redirectWithStatus('invalid_zip');
            }

            $file = $_FILES['kk_stack_zip'];

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || empty($file['tmp_name'])
                || !is_string($file['tmp_name'])
                || ($file['size'] ?? 0) <= 0
                || ($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES
            ) {
                self::redirectWithStatus('invalid_zip');
            }

            $name = sanitize_file_name((string) ($file['name'] ?? ''));

            if (!str_ends_with(strtolower($name), '.zip')) {
                self::redirectWithStatus('invalid_zip');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';

            $moved = wp_handle_upload($file, [
                'test_form' => false,
                'mimes' => ['zip' => 'application/zip'],
            ]);

            if (isset($moved['error']) || empty($moved['file'])) {
                self::rememberError((string) ($moved['error'] ?? ''));
                self::redirectWithStatus('upload_failed');
            }

            $uploadedFile = (string) $moved['file'];
            $error = self::installFromZip($uploadedFile);

            if ($error !== null) {
                self::rememberError($error);
                self::redirectWithStatus('upload_failed');
            }
        } finally {
            delete_transient(self::LOCK_TRANSIENT);

            if ($uploadedFile !== null && file_exists($uploadedFile)) {
                unlink($uploadedFile);
            }
        }

        self::redirectWithStatus('installed');
    }

    public static function handleActivate(): void
    {
        check_admin_referer('kk_setup_activate');

        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to activate plugins.', 'koperasikita'));
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (is_plugin_active(self::PLUGIN_FILE)) {
            self::redirectWithStatus('already_active');
        }

        $valid = validate_plugin(self::PLUGIN_FILE);

        if (is_wp_error($valid)) {
            self::rememberError($valid->get_error_message());
            self::redirectWithStatus('activate_failed');
        }

        $result = activate_plugin(self::PLUGIN_FILE);

        if (is_wp_error($result)) {
            self::rememberError($result->get_error_message());
            self::redirectWithStatus('activate_failed');
        }

        self::redirectWithStatus('activated');
    }

    /**
     * Save the Fast Search behaviour toggles from the Setup card.
     * The form always submits a hidden `0` before each checkbox,
     * so an unchecked box arrives as `0` and the setting is really
     * turned off instead of keeping its old value.
     */
    public static function handleFastSearchSave(): void
    {
        check_admin_referer('kk_fast_search_save');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage options.', 'koperasikita'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed here, FastSearch::saveSettings reads only its two known keys and casts them to bool.
        $result = \KoperasiKita\Features\FastSearch\FastSearch::saveSettings(wp_unslash($_POST));

        self::redirectWithStatus($result['success'] ? 'fast_search_saved' : 'fast_search_save_failed');
    }

    /**
     * @return 'active'|'installed'|'missing'
     */
    public static function stackStatus(): string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (is_plugin_active(self::PLUGIN_FILE)) {
            return 'active';
        }

        if (file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE)) {
            return 'installed';
        }

        return 'missing';
    }

    /**
     * @return array<int, array{name: string, detail: string, state: string}>
     */
    private static function checks(string $stackStatus): array
    {
        global $wp_version;

        $checks = [];

        $checks[] = [
            'name' => __('PHP version', 'koperasikita'),
            'detail' => PHP_VERSION,
            'state' => PHP_VERSION_ID >= 80300 ? 'pass' : 'fail',
        ];

        $checks[] = [
            'name' => __('WordPress version', 'koperasikita'),
            'detail' => (string) $wp_version,
            'state' => version_compare((string) $wp_version, '6.5', '>=') ? 'pass' : 'fail',
        ];

        $checks[] = [
            'name' => __('TrayDigita Stack', 'koperasikita'),
            'detail' => $stackStatus === 'active'
                ? __('Active', 'koperasikita')
                : ($stackStatus === 'installed'
                    ? __('Installed, not active', 'koperasikita')
                    : __('Not installed', 'koperasikita')),
            'state' => $stackStatus === 'active' ? 'pass' : ($stackStatus === 'installed' ? 'warn' : 'fail'),
        ];

        $bookmarks = self::bookmarksPageUrl() !== admin_url('edit.php?post_type=page');

        $checks[] = [
            'name' => __('Bookmarks page', 'koperasikita'),
            'detail' => $bookmarks
                ? __('Page found', 'koperasikita')
                : __('No page uses the Bookmarks template yet', 'koperasikita'),
            'state' => $bookmarks ? 'pass' : 'warn',
        ];

        $uploads = wp_upload_dir();
        $writable = isset($uploads['basedir']) && wp_is_writable((string) $uploads['basedir']);

        $checks[] = [
            'name' => __('Uploads directory', 'koperasikita'),
            'detail' => $writable ? __('Writable', 'koperasikita') : __('Not writable', 'koperasikita'),
            'state' => $writable ? 'pass' : 'fail',
        ];

        return $checks;
    }

    private static function bookmarksPageUrl(): string
    {
        $url = \KoperasiKita\Features\Bookmarks::pageUrl();

        return $url !== '' ? $url : admin_url('edit.php?post_type=page');
    }

    /**
     * @return array{url: string, version: string}|\WP_Error
     */
    private static function latestReleaseAsset()
    {
        $response = wp_remote_get(self::RELEASES_API, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Koperasi-Kita-Theme/' . KK_VERSION,
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_failed', $response->get_error_message());
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('api_failed', __('GitHub answered with an unexpected status.', 'koperasikita'));
        }

        $release = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($release) || empty($release['assets']) || !is_array($release['assets'])) {
            return new \WP_Error('no_asset', __('No release assets were found.', 'koperasikita'));
        }

        foreach ($release['assets'] as $asset) {
            if (!is_array($asset) || !isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }

            if (!preg_match('/^traydigita-stack-[0-9][^\/]*\.zip$/', (string) $asset['name'])) {
                continue;
            }

            $url = (string) $asset['browser_download_url'];
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (!in_array($host, ['github.com', 'objects.githubusercontent.com'], true)) {
                return new \WP_Error('invalid_url', __('The release download URL was rejected.', 'koperasikita'));
            }

            return [
                'url' => $url,
                'version' => (string) ($release['tag_name'] ?? ''),
            ];
        }

        return new \WP_Error('no_asset', __('No Stack release asset was found.', 'koperasikita'));
    }

    /**
     * @return string|null Error message, or null on success.
     */
    private static function installFromUrl(string $url): ?string
    {
        $fsError = self::requireDirectFilesystem();

        if ($fsError !== null) {
            return $fsError;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp = download_url($url, 300);

        if (is_wp_error($tmp)) {
            return $tmp->get_error_message();
        }

        try {
            return self::installFromZip((string) $tmp);
        } finally {
            if (file_exists((string) $tmp)) {
                unlink((string) $tmp);
            }
        }
    }

    /**
     * @return string|null Error message, or null on success.
     */
    private static function installFromZip(string $zipPath): ?string
    {
        $fsError = self::requireDirectFilesystem();

        if ($fsError !== null) {
            return $fsError;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $skin = new SetupUpgraderSkin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->install($zipPath);

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        if ($result === false) {
            $errors = $skin->getErrors();

            return $errors !== [] ? implode(' ', $errors) : __('The plugin could not be installed.', 'koperasikita');
        }

        $valid = validate_plugin(self::PLUGIN_FILE);

        if (is_wp_error($valid)) {
            return $valid->get_error_message();
        }

        return null;
    }

    /**
     * The installer only works with direct filesystem access. Anything
     * else would ask for FTP credentials mid-flow, which this page
     * deliberately does not do.
     */
    private static function requireDirectFilesystem(): ?string
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct') {
            return __('Direct filesystem access is not available. Upload the plugin from Plugins → Add New instead.', 'koperasikita');
        }

        return null;
    }

    private static function rememberError(string $message): void
    {
        if ($message === '') {
            return;
        }

        set_transient(self::ERROR_TRANSIENT, wp_strip_all_tags($message), 60);
    }

    /**
     * @return array{0: string, 1: string} [kind, message]
     */
    private static function statusMessage(string $status): array
    {
        return match ($status) {
            'installed' => ['success', __('TrayDigita Stack installed. Activate it below to enable the integrations.', 'koperasikita')],
            'activated' => ['success', __('TrayDigita Stack is now active.', 'koperasikita')],
            'already_active' => ['success', __('TrayDigita Stack is already active.', 'koperasikita')],
            'locked' => ['error', __('Another Stack installation is already running. Please wait a moment and try again.', 'koperasikita')],
            'no_direct_fs' => ['error', __('Direct filesystem access is not available. Upload the plugin from Plugins → Add New instead.', 'koperasikita')],
            'invalid_zip' => ['error', __('The uploaded file is not a valid plugin zip (max 20 MB).', 'koperasikita')],
            'invalid_url' => ['error', __('The release download URL was rejected.', 'koperasikita')],
            'no_asset' => ['error', __('No Stack release asset was found.', 'koperasikita')],
            'api_failed' => ['error', __('Could not reach the GitHub release feed. Check the connection and try again.', 'koperasikita')],
            'install_failed' => ['error', __('Installation failed.', 'koperasikita')],
            'upload_failed' => ['error', __('Upload failed.', 'koperasikita')],
            'activate_failed' => ['error', __('Activation failed.', 'koperasikita')],
            'fast_search_saved' => ['success', __('Fast Search settings saved.', 'koperasikita')],
            'fast_search_save_failed' => ['error', __('Fast Search settings could not be saved.', 'koperasikita')],
            default => ['error', __('The installation could not be completed.', 'koperasikita')],
        };
    }

    private static function redirectWithStatus(string $status): void
    {
        wp_safe_redirect(add_query_arg('kk_setup_status', $status, self::pageUrl()));
        exit;
    }
}

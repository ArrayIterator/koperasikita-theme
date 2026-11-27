<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Api\Ajax;

/**
 * The Appearance -> Sidebar Settings admin screen.
 *
 * Sticky is a per-sidebar setting, not a global Customizer toggle:
 * each sidebar area gets its own "Sticky while scrolling" checkbox
 * here. Only true sidebars get the setting - the full-width content
 * strips (Below Header, Before Latest News, Before Highlights,
 * Block Area) never stick; the two hero widget areas do.
 *
 * Stored in the `kk_sidebar_settings` option as
 * ['sticky' => ['sidebar-1' => true, ...]].
 */
final class SidebarSettings
{
    public const OPTION = 'kk_sidebar_settings';

    public const PAGE_SLUG = 'kk-sidebar-settings';

    /**
     * Sidebar areas that may stick. id => [name, description].
     * Content strips are deliberately absent.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stickySidebars(): array
    {
        return [
            'sidebar-1' => [
                esc_html__('Main Sidebar', 'koperasikita'),
                esc_html__('Right column of the blog and archive pages.', 'koperasikita'),
            ],
            'sidebar-single' => [
                esc_html__('Article Sidebar', 'koperasikita'),
                esc_html__('Right column of a single article page.', 'koperasikita'),
            ],
            'sidebar-page' => [
                esc_html__('Page Sidebar', 'koperasikita'),
                esc_html__('Right column of a single page.', 'koperasikita'),
            ],
            'sidebar-news' => [
                esc_html__('News Sidebar', 'koperasikita'),
                esc_html__('Right column of the homepage.', 'koperasikita'),
            ],
            'sidebar-404' => [
                esc_html__('404 Sidebar', 'koperasikita'),
                esc_html__('Right column of the 404 page and the search page when no results are found.', 'koperasikita'),
            ],
            'sidebar-agenda' => [
                esc_html__('Agenda Sidebar', 'koperasikita'),
                esc_html__('Right column of the agenda pages (single event and agenda archive).', 'koperasikita'),
            ],
            'kk-hero-left' => [
                esc_html__('Hero Left Widget', 'koperasikita'),
                esc_html__('Left column of the homepage hero.', 'koperasikita'),
            ],
            'kk-hero-right' => [
                esc_html__('Hero Right Widget', 'koperasikita'),
                esc_html__('Right column of the homepage hero.', 'koperasikita'),
            ],
        ];
    }

    public static function register(): void
    {
        // The page itself lives under the single "Koperasi Kita"
        // top-level menu (AdminMenu); only the settings
        // registration stays here. The Setup page popup saves
        // through kk_sidebar_settings_save (same sanitizer as the
        // full page, so the two saves can never drift apart).
        Ajax::add('kk_sidebar_settings_save', [self::class, 'ajaxSave'], false, 'manage_options');
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * The AJAX save endpoint (kk_sidebar_settings_save): the Setup
     * page popup posts the checked sticky sidebars as a comma list.
     * Unchecked sidebars are absent from the list and save as
     * false. The shared nonce + manage_options are enforced by
     * Ajax::dispatch.
     *
     * @return array{message: string}
     */
    public static function ajaxSave(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);
        $ids = isset($raw['sticky']) && is_string($raw['sticky']) && trim($raw['sticky']) !== ''
            ? array_filter(array_map('sanitize_key', explode(',', $raw['sticky'])))
            : [];
        $clean = self::sanitizeSettings(['sticky' => array_fill_keys($ids, true)]);
        update_option(self::OPTION, $clean);

        return [
            'message' => __('Sidebar settings saved.', 'koperasikita'),
        ];
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
            'default' => [],
        ]);
    }

    /**
     * Sanitizer: keep only known sidebar ids, coerce to bool.
     *
     * @param mixed $input Raw submitted value.
     * @return array{sticky: array<string, bool>}
     */
    public static function sanitizeSettings($input): array
    {
        $clean = ['sticky' => []];

        if (!is_array($input)) {
            $input = [];
        }

        $submitted = isset($input['sticky']) && is_array($input['sticky']) ? $input['sticky'] : [];

        foreach (self::stickySidebars() as $id => $meta) {
            $clean['sticky'][$id] = !empty($submitted[$id]);
        }

        return $clean;
    }

    /** Whether the given sidebar sticks while scrolling. */
    public static function isSticky(string $sidebarId): bool
    {
        $settings = get_option(self::OPTION, []);
        $sticky = isset($settings['sticky']) && is_array($settings['sticky']) ? $settings['sticky'] : [];

        return !empty($sticky[$sidebarId]);
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(self::OPTION, []);
        $sticky = isset($settings['sticky']) && is_array($settings['sticky']) ? $settings['sticky'] : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sidebar Settings', 'koperasikita'); ?></h1>
            <p><?php esc_html_e('Choose which sidebars stay visible while the reader scrolls. Only sidebars can stick — the full-width widget areas (Below Header, Before Latest News, Before Highlights, Block Area) always scroll with the page.', 'koperasikita'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach (self::stickySidebars() as $id => [$name, $description]) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html($name); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[sticky][<?php echo esc_attr($id); ?>]" value="1"<?php checked(!empty($sticky[$id])); ?>>
                                        <?php esc_html_e('Sticky while scrolling', 'koperasikita'); ?>
                                    </label>
                                    <p class="description"><?php echo esc_html($description); ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

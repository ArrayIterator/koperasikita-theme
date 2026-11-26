<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

use KoperasiKita\Icons\Icon;

/**
 * Menu item icons: a "Choose icon" button on Appearance -> Menus that
 * opens a popup with a searchable grid of the theme's Feather icons
 * (290), plus a preview of the menu item with the chosen icon.
 *
 * The choice is saved as post meta on the menu item. On the frontend
 * the icon is prepended to the item title via the nav_menu_item_title
 * filter, so it shows in the primary nav, the mobile bottom bar, the
 * footer nav and the sidebar menu alike — no custom walker needed.
 */
final class MenuIcons
{
    public const META_KEY = '_kk_menu_icon';

    public static function register(): void
    {
        add_action('wp_nav_menu_item_custom_fields', [self::class, 'renderField'], 10, 4);
        add_action('wp_update_nav_menu_item', [self::class, 'saveField'], 10, 2);
        add_filter('nav_menu_item_title', [self::class, 'prependIcon'], 10, 4);
        add_action('admin_footer-nav-menus.php', [self::class, 'renderPopup']);
    }

    /** The icon name saved on a menu item, or '' when none / invalid. */
    public static function icon(int $itemId): string
    {
        $icon = get_post_meta($itemId, self::META_KEY, true);

        if (!is_string($icon) || !Icon::exists($icon)) {
            return '';
        }

        return $icon;
    }

    /** Prepend the saved icon to the menu item title (inside the <a>). */
    public static function prependIcon(string $title, \WP_Post $item, $args, int $depth): string
    {
        $icon = self::icon((int) $item->ID);

        if ('' === $icon) {
            return $title;
        }

        return '<span class="kk-menu-icon" aria-hidden="true">' . Icon::svg($icon, 18) . '</span>' . $title;
    }

    /** The icon field inside each menu item's settings on Appearance -> Menus. */
    public static function renderField(int $itemId, \WP_Post $item, int $depth, $args): void
    {
        $current = self::icon($itemId);
        ?>
        <p class="description description-wide kk-menu-icon-field">
            <span class="kk-menu-icon-field-label"><?php esc_html_e('Icon', 'koperasikita'); ?></span>
            <span class="kk-menu-icon-row">
                <button type="button" class="button kk-menu-icon-pick" data-kk-menu-icon-pick="<?php echo (int) $itemId; ?>">
                    <?php esc_html_e('Choose icon', 'koperasikita'); ?>
                </button>
                <span class="kk-menu-icon-preview" data-kk-menu-icon-preview="<?php echo (int) $itemId; ?>" data-kk-menu-icon-title="<?php echo esc_attr($item->title); ?>">
                    <?php echo self::previewInner($item, $current); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </span>
            </span>
            <input type="hidden" name="kk-menu-icon[<?php echo (int) $itemId; ?>]" value="<?php echo esc_attr($current); ?>" data-kk-menu-icon-input="<?php echo (int) $itemId; ?>">
        </p>
        <?php
    }

    /** The icon + title shown in the field preview (rebuilt by the picker JS). */
    private static function previewInner(\WP_Post $item, string $icon): string
    {
        if ('' === $icon) {
            return '<span class="kk-menu-icon-preview-empty">' . esc_html__('No icon', 'koperasikita') . '</span>';
        }

        return Icon::svg($icon, 18) . '<span>' . esc_html($item->title) . '</span>';
    }

    /** The shared icon picker popup (one per page, reused by every menu item). */
    public static function renderPopup(): void
    {
        ?>
        <div class="kk-menu-icon-popup" id="kk-menu-icon-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-menu-icon-popup-title">
            <div class="kk-menu-icon-popup-backdrop" data-kk-menu-icon-close></div>
            <div class="kk-menu-icon-popup-panel" role="document">
                <div class="kk-menu-icon-popup-head">
                    <h2 id="kk-menu-icon-popup-title"><?php esc_html_e('Choose menu icon', 'koperasikita'); ?></h2>
                    <div class="kk-menu-icon-popup-actions">
                        <button type="button" class="button" data-kk-menu-icon-remove><?php esc_html_e('Remove icon', 'koperasikita'); ?></button>
                        <button type="button" class="button" data-kk-menu-icon-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <p class="kk-menu-icon-popup-search">
                    <label for="kk-menu-icon-search"><?php esc_html_e('Search icons', 'koperasikita'); ?></label>
                    <input type="search" id="kk-menu-icon-search" class="widefat" autocomplete="off" placeholder="<?php echo esc_attr__('Type to filter icons…', 'koperasikita'); ?>">
                </p>
                <div class="kk-menu-icon-popup-grid" data-kk-menu-icon-grid></div>
                <p class="kk-menu-icon-popup-empty" data-kk-menu-icon-empty hidden><?php esc_html_e('No icons match your search.', 'koperasikita'); ?></p>
            </div>
        </div>
        <?php
    }

    /** Save the picked icon (or delete it when cleared). */
    public static function saveField(int $menuId, int $itemId): void
    {
        if (!isset($_POST['kk-menu-icon'][$itemId])) {
            return;
        }

        $icon = sanitize_text_field(wp_unslash($_POST['kk-menu-icon'][$itemId]));

        if ('' === $icon || !Icon::exists($icon)) {
            delete_post_meta($itemId, self::META_KEY);
            return;
        }

        update_post_meta($itemId, self::META_KEY, $icon);
    }
}

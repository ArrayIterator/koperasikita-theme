<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

use KoperasiKita\Icons\Icon;

/**
 * Menu item icons: a visual icon picker on Appearance -> Menus and the
 * icon in front of the label wherever the menu renders.
 *
 * The picker is a radio grid of the theme's icons (see Icon::names());
 * the choice is saved as post meta on the menu item. On the frontend
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

    /** The icon picker inside each menu item's settings on Appearance -> Menus. */
    public static function renderField(int $itemId, \WP_Post $item, int $depth, $args): void
    {
        $current = self::icon($itemId);
        ?>
        <p class="description description-wide kk-menu-icon-field">
            <label><?php esc_html_e('Icon', 'koperasikita'); ?></label>
            <span class="kk-menu-icon-grid">
                <label class="kk-menu-icon-option kk-menu-icon-option--none">
                    <input type="radio" name="kk-menu-icon[<?php echo (int) $itemId; ?>]" value=""<?php checked('', $current); ?>>
                    <span class="kk-menu-icon-swatch"><?php esc_html_e('No icon', 'koperasikita'); ?></span>
                </label>
                <?php foreach (Icon::names() as $name) : ?>
                    <label class="kk-menu-icon-option" title="<?php echo esc_attr($name); ?>">
                        <input type="radio" name="kk-menu-icon[<?php echo (int) $itemId; ?>]" value="<?php echo esc_attr($name); ?>"<?php checked($name, $current); ?>>
                        <span class="kk-menu-icon-swatch"><?php echo Icon::svg($name, 20); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    </label>
                <?php endforeach; ?>
            </span>
        </p>
        <?php
    }

    /** Save the picked icon (or delete it when "No icon" is picked). */
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

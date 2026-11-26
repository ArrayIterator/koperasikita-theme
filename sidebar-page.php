<?php
/**
 * The page sidebar.
 *
 * @package KoperasiKita
 */

// An empty sidebar renders nothing — no placeholder box. The
// editor adds widgets in Appearance -> Widgets.
if ( ! is_active_sidebar( 'sidebar-page' ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( 'sidebar-page' ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-page' ); ?>
</aside>

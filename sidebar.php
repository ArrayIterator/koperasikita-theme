<?php
/**
 * The main sidebar.
 *
 * Renders the Main Sidebar widgets only - no placeholder box when
 * the area is empty. The editor adds widgets in Appearance ->
 * Widgets. The Mobile Menu location (Appearance -> Menus) belongs
 * to the mobile drawer only; it is never rendered here.
 *
 * @package KoperasiKita
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( 'sidebar-1' ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>

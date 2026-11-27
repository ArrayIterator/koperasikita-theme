<?php
/**
 * The 404 / search-not-found sidebar.
 *
 * Renders nothing when the 404 Sidebar has no widgets - the page
 * then falls back to full width. No fallback to the Main Sidebar:
 * the 404 area is its own area.
 *
 * @package KoperasiKita
 */

if ( ! is_active_sidebar( 'sidebar-404' ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( 'sidebar-404' ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-404' ); ?>
</aside>

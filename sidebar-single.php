<?php
/**
 * The single-post sidebar.
 *
 * @package KoperasiKita
 */

// An empty sidebar renders nothing — no placeholder box. The
// editor adds widgets in Appearance -> Widgets.
if ( ! is_active_sidebar( 'sidebar-single' ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( 'sidebar-single' ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-single' ); ?>
</aside>

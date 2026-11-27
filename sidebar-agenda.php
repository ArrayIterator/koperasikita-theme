<?php
/**
 * The agenda sidebar.
 *
 * Renders the Agenda Sidebar widgets only - no fallback to any other
 * sidebar, so an agenda page shows exactly what the editor placed in
 * the Agenda Sidebar, and nothing at all while it is empty.
 *
 * @package KoperasiKita
 */

$sidebar = 'sidebar-agenda';

if ( ! is_active_sidebar( $sidebar ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( $sidebar ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( $sidebar ); ?>
</aside>

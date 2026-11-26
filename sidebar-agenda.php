<?php
/**
 * The agenda sidebar.
 *
 * Renders nothing when the Agenda Sidebar has no widgets. Falls back
 * to the Article Sidebar so agenda pages are never bare just because
 * the editor only filled one sidebar.
 *
 * @package KoperasiKita
 */

$sidebar = 'sidebar-agenda';

if ( ! is_active_sidebar( $sidebar ) && is_active_sidebar( 'sidebar-single' ) ) {
	$sidebar = 'sidebar-single';
}

if ( ! is_active_sidebar( $sidebar ) ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( $sidebar ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( $sidebar ); ?>
</aside>

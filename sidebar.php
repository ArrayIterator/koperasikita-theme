<?php
/**
 * The main sidebar.
 *
 * @package KoperasiKita
 */

// An empty sidebar renders nothing - no placeholder box. The
// editor adds widgets in Appearance -> Widgets. A menu assigned to
// the Sidebar Menu location (Appearance -> Menus) also counts: the
// sidebar renders when it has widgets, a sidebar menu, or both.
$has_widgets = is_active_sidebar( 'sidebar-1' );
$has_menu = has_nav_menu( 'sidebar' );

if ( ! $has_widgets && ! $has_menu ) {
	return;
}
?>

<aside class="kk-sidebar<?php echo \KoperasiKita\Admin\SidebarSettings::isSticky( 'sidebar-1' ) ? ' kk-sidebar--sticky' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php if ( $has_menu ) : ?>
		<nav class="kk-sidebar-nav" aria-label="<?php esc_attr_e( 'Sidebar Menu', 'koperasikita' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'sidebar',
					'menu_class'     => 'kk-sidebar-nav-menu',
					'container'      => false,
					'fallback_cb'    => false,
					// Depth 0 (unlimited): the sidebar menu renders
					// every nesting level, same as the drawer.
					'depth'          => 0,
				)
			);
			?>
		</nav>
	<?php endif; ?>
	<?php
	if ( $has_widgets ) {
		dynamic_sidebar( 'sidebar-1' );
	}
	?>
</aside>

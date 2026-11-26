<?php
/**
 * The main sidebar.
 *
 * @package KoperasiKita
 */

// An empty sidebar renders nothing — no placeholder box. The
// editor adds widgets in Appearance -> Widgets.
if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>

<aside class="kk-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>

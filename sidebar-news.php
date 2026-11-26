<?php
/**
 * The homepage news sidebar.
 *
 * @package KoperasiKita
 */

// An empty sidebar renders nothing — no placeholder box. The
// editor adds widgets in Appearance -> Widgets.
if ( ! is_active_sidebar( 'sidebar-news' ) ) {
	return;
}
?>

<aside class="kk-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-news' ); ?>
</aside>

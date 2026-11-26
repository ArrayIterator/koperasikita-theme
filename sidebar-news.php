<?php
/**
 * The homepage news sidebar.
 *
 * Shows the News Sidebar widgets; when that area is empty it falls
 * back to the Main Sidebar, so the homepage is never bare just
 * because the editor only filled one sidebar. When neither area has
 * widgets, an empty sidebar renders nothing — no placeholder box.
 * The editor adds widgets in Appearance -> Widgets.
 *
 * @package KoperasiKita
 */

$sidebar = 'sidebar-news';

if ( ! is_active_sidebar( $sidebar ) && is_active_sidebar( 'sidebar-1' ) ) {
	$sidebar = 'sidebar-1';
}

if ( ! is_active_sidebar( $sidebar ) ) {
	return;
}
?>

<aside class="kk-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( $sidebar ); ?>
</aside>

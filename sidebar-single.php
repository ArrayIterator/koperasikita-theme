<?php
/**
 * The single-post sidebar.
 *
 * @package KoperasiKita
 */

if ( ! is_active_sidebar( 'sidebar-single' ) ) {
	if ( current_user_can( 'edit_theme_options' ) ) {
		?>
		<aside class="kk-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
			<p class="kk-sidebar-empty"><?php esc_html_e( 'No widgets added yet. Add widgets in Appearance &rarr; Widgets.', 'koperasikita' ); ?></p>
		</aside>
		<?php
	}
	return;
}
?>

<aside class="kk-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'koperasikita' ); ?>">
	<?php dynamic_sidebar( 'sidebar-single' ); ?>
</aside>

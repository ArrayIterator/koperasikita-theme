<?php
/**
 * Grid / list / newsletter layout switcher for the latest-news section.
 *
 * @package KoperasiKita
 */

$current_layout = get_theme_mod( 'kk_homepage_layout', 'grid' );
$current_layout = in_array( $current_layout, array( 'grid', 'list', 'newsletter' ), true ) ? $current_layout : 'grid';
?>

<div class="kk-layout-switcher" role="group" aria-label="<?php esc_attr_e( 'Layout', 'koperasikita' ); ?>">
	<button type="button" class="kk-layout-button<?php echo 'grid' === $current_layout ? ' is-active' : ''; ?>" data-kk-layout="grid" aria-pressed="<?php echo 'grid' === $current_layout ? 'true' : 'false'; ?>">
		<?php echo \KoperasiKita\Icons\Icon::svg( 'grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php esc_html_e( 'Grid', 'koperasikita' ); ?></span>
	</button>
	<button type="button" class="kk-layout-button<?php echo 'list' === $current_layout ? ' is-active' : ''; ?>" data-kk-layout="list" aria-pressed="<?php echo 'list' === $current_layout ? 'true' : 'false'; ?>">
		<?php echo \KoperasiKita\Icons\Icon::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php esc_html_e( 'List', 'koperasikita' ); ?></span>
	</button>
	<button type="button" class="kk-layout-button<?php echo 'newsletter' === $current_layout ? ' is-active' : ''; ?>" data-kk-layout="newsletter" aria-pressed="<?php echo 'newsletter' === $current_layout ? 'true' : 'false'; ?>">
		<?php echo \KoperasiKita\Icons\Icon::svg( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php esc_html_e( 'Newsletter', 'koperasikita' ); ?></span>
	</button>
</div>

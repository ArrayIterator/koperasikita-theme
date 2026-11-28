<?php
/**
 * Grid / list layout switcher for archive pages (category, tag,
 * author, date, search, agenda). The visitor's choice is stored in
 * a `kk_archive_layout` cookie (and in localStorage) and the server
 * reads the cookie, so the archive renders in the right layout on
 * first paint - no flash, no JS dependency.
 *
 * @package KoperasiKita
 */

$current_layout = \KoperasiKita\Features\Pagination::archiveLayout();
?>

<div class="kk-archive-toolbar">
	<div class="kk-layout-switcher" role="group" aria-label="<?php esc_attr_e( 'Layout', 'koperasikita' ); ?>">
		<button type="button" class="kk-layout-button<?php echo 'grid' === $current_layout ? ' is-active' : ''; ?>" data-kk-archive-layout="grid" aria-pressed="<?php echo 'grid' === $current_layout ? 'true' : 'false'; ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'Grid', 'koperasikita' ); ?></span>
		</button>
		<button type="button" class="kk-layout-button<?php echo 'list' === $current_layout ? ' is-active' : ''; ?>" data-kk-archive-layout="list" aria-pressed="<?php echo 'list' === $current_layout ? 'true' : 'false'; ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'List', 'koperasikita' ); ?></span>
		</button>
	</div>
</div>

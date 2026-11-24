<?php
/**
 * Primary navigation + search overlay (centered modal).
 *
 * @package KoperasiKita
 */

?>

<div class="kk-nav-wrap">
	<div class="kk-shell kk-nav-inner">
		<button type="button" class="kk-icon-button kk-menu-toggle" id="kk-menu-toggle" aria-label="<?php esc_attr_e( 'Open menu', 'koperasikita' ); ?>" aria-expanded="false" aria-controls="kk-primary-nav">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'menu', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>

		<nav id="kk-primary-nav" class="kk-primary-nav" aria-label="<?php esc_attr_e( 'Primary', 'koperasikita' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'menu_class'     => 'kk-primary-menu',
					'container'      => false,
					'fallback_cb'    => 'wp_page_menu',
					'depth'          => 2,
				)
			);
			?>
		</nav>

		<?php get_template_part( 'template-parts/pwa/install-button' ); ?>
	</div>
</div>

<div class="kk-modal" id="kk-search-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Search', 'koperasikita' ); ?>" hidden>
	<div class="kk-modal-backdrop" data-kk-modal-close></div>
	<div class="kk-modal-dialog kk-search-dialog">
		<button type="button" class="kk-icon-button kk-modal-close" data-kk-modal-close aria-label="<?php esc_attr_e( 'Close search', 'koperasikita' ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
		<p class="kk-eyebrow"><?php esc_html_e( 'Search', 'koperasikita' ); ?></p>
		<?php get_search_form(); ?>
	</div>
</div>

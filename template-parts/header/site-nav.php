<?php
/**
 * Primary navigation as a slide-in sidebar (mobile drawer) + header
 * actions + search dialog.
 *
 * On mobile the hamburger opens the nav as a drawer sliding in from
 * the left, over a blurred backdrop; on desktop the same nav is the
 * horizontal bar. The drawer header carries the logo, a close button
 * and — when a sub-menu is open — a back button (drill-down).
 *
 * Search and the colour-scheme toggle live here in the sticky bar,
 * not in the top bar. The PWA install button never lives here either:
 * its position is a Customizer setting (footer by default).
 *
 * The search dialog is a native <dialog>: a compact centred box with
 * a backdrop, an icon badge, a title and a search form with a submit
 * button.
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
			<div class="kk-drawer-head">
				<span class="kk-drawer-brand">
					<img class="kk-drawer-brand-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo.svg' ); ?>" alt="" width="32" height="32" aria-hidden="true">
					<span><?php esc_html_e( 'Menu', 'koperasikita' ); ?></span>
				</span>
				<button type="button" class="kk-icon-button kk-drawer-close" id="kk-drawer-close" aria-label="<?php esc_attr_e( 'Close menu', 'koperasikita' ); ?>">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>

			<div class="kk-drawer-back" id="kk-drawer-back" hidden>
				<button type="button" class="kk-drawer-back-button" id="kk-drawer-back-button">
					<span aria-hidden="true">&lsaquo;</span>
					<span id="kk-drawer-back-label"></span>
				</button>
			</div>

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

		<div class="kk-nav-actions">
			<button type="button" class="kk-icon-button kk-search-open" id="kk-search-open" aria-label="<?php esc_attr_e( 'Search', 'koperasikita' ); ?>" aria-haspopup="dialog" aria-controls="kk-search-dialog">
				<?php echo \KoperasiKita\Icons\Icon::svg( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>

			<button type="button" class="kk-icon-button kk-color-scheme-toggle" id="kk-color-scheme-toggle" aria-label="<?php esc_attr_e( 'Toggle color scheme', 'koperasikita' ); ?>" aria-pressed="false">
				<span class="kk-icon-sun"><?php echo \KoperasiKita\Icons\Icon::svg( 'sun', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="kk-icon-moon"><?php echo \KoperasiKita\Icons\Icon::svg( 'moon', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>
		</div>
	</div>
</div>

<div class="kk-nav-backdrop" id="kk-nav-backdrop" hidden></div>

<dialog class="kk-search-dialog" id="kk-search-dialog" aria-labelledby="kk-search-dialog-title">
	<div class="kk-search-dialog-inner">
		<button type="button" class="kk-icon-button kk-search-dialog-close" id="kk-search-close" aria-label="<?php esc_attr_e( 'Close search', 'koperasikita' ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
		<p class="kk-search-dialog-badge" aria-hidden="true"><?php echo \KoperasiKita\Icons\Icon::svg( 'search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<h2 class="kk-search-dialog-title" id="kk-search-dialog-title"><?php esc_html_e( 'Search', 'koperasikita' ); ?></h2>
		<form class="kk-search-dialog-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="kk-search-input"><?php esc_html_e( 'Search for:', 'koperasikita' ); ?></label>
			<input class="search-field" id="kk-search-input" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Search news…', 'koperasikita' ); ?>">
			<button type="submit" class="kk-button search-submit"><?php esc_html_e( 'Search', 'koperasikita' ); ?></button>
		</form>
	</div>
</dialog>

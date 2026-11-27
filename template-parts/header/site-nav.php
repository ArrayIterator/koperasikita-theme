<?php
/**
 * Header navigation: desktop bar + mobile drawer + header actions
 * + search dialog.
 *
 * Two menus, one bar: the desktop bar (900px and up) is the Primary
 * menu, and the mobile drawer (below 900px) is the Sidebar menu -
 * the hamburger opens the drawer sliding in from the left, over a
 * blurred backdrop. The drawer falls back to the Primary menu when
 * no menu is assigned to the sidebar location, so it is never empty
 * on a fresh install. The drawer header carries the logo, a close
 * button and - when a sub-menu is open - a back button (drill-down).
 *
 * Search and the colour-scheme toggle live here in the sticky bar,
 * not in the top bar. The PWA install button never lives here either:
 * its position is a Customizer setting (footer by default).
 *
 * Search is an expanding field, not a popup: the search icon is the
 * toggle and the input grows into a FULL bar when opened - the
 * desktop menu / mobile hamburger hides and the field fills the
 * whole nav row on desktop exactly as on mobile (Escape, outside
 * click or submit closes it again).
 *
 * @package KoperasiKita
 */

?>

<div class="kk-nav-wrap">
	<div class="kk-shell kk-nav-inner">
		<button type="button" class="kk-icon-button kk-menu-toggle" id="kk-menu-toggle" aria-label="<?php esc_attr_e( 'Open menu', 'koperasikita' ); ?>" aria-expanded="false" aria-controls="kk-primary-nav">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'menu', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>

		<!-- Desktop bar: the Primary menu. Hidden below 900px. -->
		<nav class="kk-desktop-nav" aria-label="<?php esc_attr_e( 'Primary', 'koperasikita' ); ?>">
			<?php
			// Depth 0 (unlimited): the desktop dropdown supports any
			// nesting depth - a nested sub-menu flies out to the right
			// of its parent item (responsive.css).
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'menu_class'     => 'kk-primary-menu',
					'container'      => false,
					'fallback_cb'    => 'wp_page_menu',
					'depth'          => 0,
				)
			);
			?>
		</nav>

		<!-- Mobile drawer: the Sidebar menu. Falls back to the Primary
		     menu when no menu is assigned to the sidebar location, so
		     the drawer is never empty on a fresh install. Hidden at
		     900px and up. -->
		<nav id="kk-primary-nav" class="kk-primary-nav<?php echo 'right' === get_theme_mod( 'kk_mobile_menu_side', 'left' ) ? ' kk-nav-right' : ''; ?>" aria-label="<?php esc_attr_e( 'Sidebar Menu', 'koperasikita' ); ?>">
			<div class="kk-drawer-head">
				<?php echo \KoperasiKita\Features\Logo::drawerBrand(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
			// The drawer is the Sidebar menu; Primary is the desktop
			// bar only. Fall back to Primary when no menu is assigned
			// to the sidebar location, so the drawer is never empty.
			// Depth 0 (unlimited): the drill-down supports any nesting
			// depth, so a deeply nested test menu drills all the way.
			$drawer_location = has_nav_menu( 'sidebar' ) ? 'sidebar' : 'primary';
			wp_nav_menu(
				array(
					'theme_location' => $drawer_location,
					'menu_class'     => 'kk-primary-menu',
					'container'      => false,
					'fallback_cb'    => 'wp_page_menu',
					'depth'          => 0,
				)
			);
			?>

			<div class="kk-drawer-foot">
				<?php if ( get_theme_mod( 'kk_dark_mode_toggle', true ) ) : ?>
					<button type="button" class="kk-icon-button" id="kk-color-scheme-toggle-drawer" data-kk-scheme-toggle aria-label="<?php esc_attr_e( 'Toggle color scheme', 'koperasikita' ); ?>" aria-pressed="false">
						<span class="kk-icon-sun"><?php echo \KoperasiKita\Icons\Icon::svg( 'sun', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="kk-icon-moon"><?php echo \KoperasiKita\Icons\Icon::svg( 'moon', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="kk-icon-monitor"><?php echo \KoperasiKita\Icons\Icon::svg( 'monitor', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</button>
				<?php endif; ?>
			</div>
		</nav>

		<div class="kk-nav-actions">
			<form class="kk-search-expand" id="kk-search-expand" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="kk-search-input"><?php esc_html_e( 'Search for:', 'koperasikita' ); ?></label>
				<input class="kk-search-expand-input search-field" id="kk-search-input" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Search news…', 'koperasikita' ); ?>">
				<button type="button" class="kk-icon-button kk-search-expand-toggle" id="kk-search-open" aria-label="<?php esc_attr_e( 'Search', 'koperasikita' ); ?>" aria-expanded="false" aria-controls="kk-search-input">
					<span class="kk-search-expand-icon kk-search-expand-icon-search"><?php echo \KoperasiKita\Icons\Icon::svg( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="kk-search-expand-icon kk-search-expand-icon-close"><?php echo \KoperasiKita\Icons\Icon::svg( 'close', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</button>
			</form>

			<?php if ( get_theme_mod( 'kk_dark_mode_toggle', true ) ) : ?>
				<button type="button" class="kk-icon-button kk-color-scheme-toggle" id="kk-color-scheme-toggle" data-kk-scheme-toggle aria-label="<?php esc_attr_e( 'Toggle color scheme', 'koperasikita' ); ?>" aria-pressed="false">
					<span class="kk-icon-sun"><?php echo \KoperasiKita\Icons\Icon::svg( 'sun', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="kk-icon-moon"><?php echo \KoperasiKita\Icons\Icon::svg( 'moon', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="kk-icon-monitor"><?php echo \KoperasiKita\Icons\Icon::svg( 'monitor', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</button>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="kk-nav-backdrop" id="kk-nav-backdrop" hidden></div>

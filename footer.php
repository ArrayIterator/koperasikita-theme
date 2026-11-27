<?php
/**
 * The footer for the Koperasi Kita theme.
 *
 * @package KoperasiKita
 */

?>
</main><!-- #kk-main -->

<footer class="kk-site-footer">
	<div class="kk-shell">
		<?php if ( is_active_sidebar( 'kk-footer-1' ) || is_active_sidebar( 'kk-footer-2' ) || is_active_sidebar( 'kk-footer-3' ) ) : ?>
		<div class="kk-footer-grid">
			<?php if ( is_active_sidebar( 'kk-footer-1' ) ) : ?>
			<div class="kk-footer-col kk-footer-about">
				<?php dynamic_sidebar( 'kk-footer-1' ); ?>
			</div>
			<?php endif; ?>
			<?php if ( is_active_sidebar( 'kk-footer-2' ) ) : ?>
			<div class="kk-footer-col kk-footer-nav">
				<?php dynamic_sidebar( 'kk-footer-2' ); ?>
			</div>
			<?php endif; ?>
			<?php if ( is_active_sidebar( 'kk-footer-3' ) ) : ?>
			<div class="kk-footer-col kk-footer-subscribe" id="kk-subscribe">
				<?php dynamic_sidebar( 'kk-footer-3' ); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="kk-footer-bottom kk-footer-bottom--<?php echo esc_attr( \KoperasiKita\Features\Footer::alignment() ); ?>">
			<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav class="kk-footer-bottom-nav" aria-label="<?php esc_attr_e( 'Footer', 'koperasikita' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'menu_class'     => 'kk-footer-menu',
						'container'      => false,
						'fallback_cb'    => false,
						'depth'          => 1,
					)
				);
				?>
			</nav>
			<?php endif; ?>
			<p class="kk-copyright" data-kk-footer-tokens="<?php echo esc_attr( wp_json_encode( \KoperasiKita\Features\Footer::tokens() ) ); ?>" data-kk-footer-default="<?php echo esc_attr( \KoperasiKita\Features\Footer::defaultCopyrightText() ); ?>">
				<?php echo esc_html( \KoperasiKita\Features\Footer::copyrightText() ); ?>
			</p>
			<?php
			$social_links = \KoperasiKita\Features\Footer::socialLinks();

			if ( [] !== $social_links ) :
				?>
				<nav class="kk-footer-social" aria-label="<?php esc_attr_e( 'Social media', 'koperasikita' ); ?>">
					<?php foreach ( $social_links as $link ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $link['label'] ); ?>">
							<?php echo \KoperasiKita\Icons\Icon::svg( $link['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					<?php endforeach; ?>
				</nav>
				<?php
			endif;
			?>
		</div>
	</div>
</footer>

<?php
// Mobile bottom navigation (phones only, < 768px). Built from the
// mobile-bottom menu location, falling back to the primary menu when no
// menu is assigned to mobile-bottom, so the bottom bar is never empty on
// a fresh install. Menus::mobileBottomItems() decides whether there is
// anything to show; wp_nav_menu() renders it, so the markup (including
// the current-menu-item state) is identical to a directly assigned menu.
$bottom_nav_items = \KoperasiKita\Setup\Menus::mobileBottomItems();

if ( [] !== $bottom_nav_items && \KoperasiKita\Setup\Menus::isDisplayed() ) :
	$bottom_nav_location = has_nav_menu( 'mobile-bottom' ) ? 'mobile-bottom' : 'primary';
	$bottom_nav_classes = 'kk-bottom-nav' . ( \KoperasiKita\Setup\Menus::showTitles() ? '' : ' kk-bottom-nav--no-titles' );
	?>
	<nav class="<?php echo esc_attr( $bottom_nav_classes ); ?>" aria-label="<?php esc_attr_e( 'Mobile navigation', 'koperasikita' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => $bottom_nav_location,
				'menu_class'     => 'kk-bottom-nav-menu',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 1,
				// Marker for Menus::limitBottomNavItems(): cap the rendered
				// bar at the Customizer Maximum Menu Count.
				'kk_limit_bottom_nav' => true,
			)
		);
		?>
	</nav>
	<?php
endif;
?>

<?php if ( get_theme_mod( 'kk_back_to_top', true ) ) : ?>
<button type="button" class="kk-back-to-top" id="kk-back-to-top" aria-label="<?php esc_attr_e( 'Back to top', 'koperasikita' ); ?>" hidden>
	<?php echo \KoperasiKita\Icons\Icon::svg( 'arrow-right', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</button>
<?php endif; ?>

<?php wp_footer(); ?>

</body>
</html>

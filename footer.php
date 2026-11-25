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
		<div class="kk-footer-grid">
			<div class="kk-footer-col kk-footer-about">
				<?php if ( has_custom_logo() ) : ?>
					<div class="kk-footer-logo"><?php the_custom_logo(); ?></div>
				<?php endif; ?>
				<p class="kk-footer-site-name"><?php bloginfo( 'name' ); ?></p>
				<?php
				$description = get_bloginfo( 'description', 'display' );
				if ( $description ) {
					?>
					<p class="kk-footer-description"><?php echo esc_html( $description ); ?></p>
					<?php
				}
				?>
			</div>

			<nav class="kk-footer-col kk-footer-nav" aria-label="<?php esc_attr_e( 'Footer', 'koperasikita' ); ?>">
				<p class="kk-footer-heading"><?php esc_html_e( 'Navigation', 'koperasikita' ); ?></p>
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

			<div class="kk-footer-col kk-footer-subscribe" id="kk-subscribe">
				<p class="kk-footer-heading"><?php esc_html_e( 'Stay informed', 'koperasikita' ); ?></p>
				<p class="kk-footer-note">
					<?php esc_html_e( 'News, announcements and market updates from the cooperative, delivered to your inbox.', 'koperasikita' ); ?>
				</p>
				<?php
				// The [kk_subscribe] shortcode renders the MailPoet subscribe
				// form; when it is not registered (MailPoet inactive) render
				// nothing instead of printing the literal shortcode text.
				if ( shortcode_exists( 'kk_subscribe' ) ) {
					echo do_shortcode( '[kk_subscribe]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		</div>

		<div class="kk-footer-bottom kk-footer-bottom--<?php echo esc_attr( \KoperasiKita\Features\Footer::alignment() ); ?>">
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

if ( [] !== $bottom_nav_items ) :
	$bottom_nav_location = has_nav_menu( 'mobile-bottom' ) ? 'mobile-bottom' : 'primary';
	?>
	<nav class="kk-bottom-nav" aria-label="<?php esc_attr_e( 'Mobile navigation', 'koperasikita' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => $bottom_nav_location,
				'menu_class'     => 'kk-bottom-nav-menu',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 1,
			)
		);
		?>
	</nav>
	<?php
endif;
?>

<button type="button" class="kk-back-to-top" id="kk-back-to-top" aria-label="<?php esc_attr_e( 'Back to top', 'koperasikita' ); ?>" hidden>
	<?php echo \KoperasiKita\Icons\Icon::svg( 'arrow-right', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</button>

<?php wp_footer(); ?>

</body>
</html>

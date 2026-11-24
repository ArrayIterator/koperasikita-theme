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

			<div class="kk-footer-col kk-footer-subscribe">
				<p class="kk-footer-heading"><?php esc_html_e( 'Stay informed', 'koperasikita' ); ?></p>
				<p class="kk-footer-note">
					<?php esc_html_e( 'News, announcements and market updates from the cooperative, delivered to your inbox.', 'koperasikita' ); ?>
				</p>
				<?php
				// The [kk_subscribe] shortcode (MailPoet) lands in Phase 3; until then
				// render nothing instead of printing the literal shortcode text.
				if ( shortcode_exists( 'kk_subscribe' ) ) {
					echo do_shortcode( '[kk_subscribe]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		</div>

		<div class="kk-footer-bottom">
			<p class="kk-copyright">
				<?php
				/* translators: %s: Site name. */
				printf( esc_html__( 'Copyright &copy; %1$s %2$s. All rights reserved.', 'koperasikita' ), esc_html( date_i18n( 'Y' ) ), esc_html( get_bloginfo( 'name' ) ) );
				?>
			</p>
			<?php
			$footer_text = get_theme_mod( 'kk_footer_text', '' );
			if ( '' !== $footer_text ) {
				?>
				<p class="kk-footer-text"><?php echo esc_html( $footer_text ); ?></p>
				<?php
			}
			?>
		</div>
	</div>
</footer>

<?php
// Mobile bottom navigation (phones only, < 768px). Built from the mobile-bottom menu location.
if ( has_nav_menu( 'mobile-bottom' ) ) :
	?>
	<nav class="kk-bottom-nav" aria-label="<?php esc_attr_e( 'Mobile navigation', 'koperasikita' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'mobile-bottom',
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

<?php
/**
 * Newsletter subscribe call to action for the newsletter homepage layout.
 *
 * Renders the [kk_subscribe] form when it exists; otherwise a button that
 * jumps to the subscribe column in the footer. Accepts $args['hidden']
 * (bool): when true the block starts hidden and the layout switcher in
 * main.ts reveals it when the newsletter layout is active.
 *
 * @package KoperasiKita
 */

$newsletter_hidden = isset( $args['hidden'] ) && $args['hidden'];
?>
<div class="kk-newsletter-subscribe mb-2 mt-6 rounded-kk border border-kk-border border-t-2 border-t-kk-gold bg-kk-surface px-6 py-7 text-center"<?php echo $newsletter_hidden ? ' hidden' : ''; ?>>
	<p class="kk-newsletter-subscribe-icon" aria-hidden="true"><?php echo \KoperasiKita\Icons\Icon::svg( 'mail', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<h3 class="kk-newsletter-subscribe-title"><?php esc_html_e( 'Get the newsletter', 'koperasikita' ); ?></h3>
	<p class="kk-newsletter-subscribe-copy"><?php esc_html_e( 'News, announcements and market updates from the cooperative, delivered to your inbox.', 'koperasikita' ); ?></p>
	<?php
	if ( shortcode_exists( 'kk_subscribe' ) ) {
		echo do_shortcode( '[kk_subscribe]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		?>
		<p class="kk-newsletter-subscribe-fallback"><a class="kk-button" href="#kk-subscribe"><?php esc_html_e( 'Subscribe', 'koperasikita' ); ?></a></p>
		<?php
	}
	?>
</div>

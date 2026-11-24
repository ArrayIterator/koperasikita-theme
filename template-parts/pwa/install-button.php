<?php
/**
 * PWA install button (single component).
 *
 * Hidden until main.ts shows it when beforeinstallprompt fires and PWA is enabled.
 * Used by the header; never duplicate this markup elsewhere.
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'pwa' ) ) {
	return;
}
?>

<button type="button" class="kk-button kk-pwa-install" id="kk-pwa-install" hidden>
	<?php echo \KoperasiKita\Icons\Icon::svg( 'bell', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span><?php esc_html_e( 'Install app', 'koperasikita' ); ?></span>
</button>

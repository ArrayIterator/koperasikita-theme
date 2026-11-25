<?php
/**
 * PWA install button (single component).
 *
 * Hidden until main.ts shows it when beforeinstallprompt fires and PWA is enabled.
 * The service-worker URL rides along as a data attribute so pwa.ts can
 * register the real theme endpoint (subdirectory installs included).
 * Used by the header; never duplicate this markup elsewhere.
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'pwa' ) ) {
	return;
}
?>

<button type="button" class="kk-button kk-pwa-install" id="kk-pwa-install" data-kk-sw-url="<?php echo esc_url( \KoperasiKita\Features\Pwa::serviceWorkerUrl() ); ?>" aria-label="<?php echo esc_attr( \KoperasiKita\Features\Pwa::buttonText() ); ?>" hidden>
	<?php echo \KoperasiKita\Icons\Icon::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span class="kk-pwa-install-label"><?php echo esc_html( \KoperasiKita\Features\Pwa::buttonText() ); ?></span>
</button>

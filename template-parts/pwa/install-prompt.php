<?php
/**
 * PWA install prompt (centered modal + blurred backdrop).
 *
 * Shown once by main.ts. The prompt itself is hidden by default.
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'pwa' ) ) {
	return;
}
?>

<div class="kk-modal" id="kk-install-prompt" role="dialog" aria-modal="true" aria-labelledby="kk-install-prompt-title" hidden>
	<div class="kk-modal-backdrop" data-kk-modal-close></div>
	<div class="kk-modal-dialog kk-install-dialog">
		<button type="button" class="kk-icon-button kk-modal-close" data-kk-modal-close aria-label="<?php esc_attr_e( 'Dismiss', 'koperasikita' ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
		<h2 class="kk-modal-title" id="kk-install-prompt-title"><?php echo esc_html( \KoperasiKita\Features\Pwa::promptTitle() ); ?></h2>
		<p class="kk-modal-text">
			<?php echo esc_html( \KoperasiKita\Features\Pwa::promptText() ); ?>
		</p>
		<ul class="kk-install-benefits">
			<li><?php esc_html_e( 'Read the latest news offline', 'koperasikita' ); ?></li>
			<li><?php esc_html_e( 'Quick access from your home screen', 'koperasikita' ); ?></li>
			<li><?php esc_html_e( 'No app store download needed', 'koperasikita' ); ?></li>
		</ul>
		<div class="kk-modal-actions">
			<button type="button" class="kk-button" id="kk-install-confirm"><?php esc_html_e( 'Install', 'koperasikita' ); ?></button>
			<button type="button" class="kk-button kk-button-ghost" data-kk-modal-close><?php esc_html_e( 'Not now', 'koperasikita' ); ?></button>
		</div>
	</div>
</div>

<?php
/**
 * App install button / banner.
 *
 * One template, four shapes, chosen by the Customizer style
 * (kk_pwa_button_style): a banner sliding down above the header, an
 * inline button beside the clock or the weather in the top bar, or a
 * floating button/bar at the bottom. The install action button keeps
 * the id kk-pwa-install in every shape: main.ts shows it when
 * beforeinstallprompt fires and runs the install prompt on click.
 * The dismiss (X) hides it for an hour via a cookie.
 *
 * @var array{style?: string} $args
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'pwa' ) ) {
	return;
}

$style = is_string( $args['style'] ?? null ) ? $args['style'] : 'float-bottom-right';
$style = in_array( $style, array( 'float-bottom', 'float-bottom-right', 'float-bottom-left', 'float-bottom-center', 'beside-weather', 'beside-clock', 'above-header' ), true ) ? $style : 'float-bottom-right';
$hidden_attr = is_customize_preview() ? '' : ' hidden';
$button_text = \KoperasiKita\Features\Pwa::buttonText();
$button_desc = \KoperasiKita\Features\Pwa::buttonDescription();
$sw_url = \KoperasiKita\Features\Pwa::serviceWorkerUrl();
?>

<?php if ( 'above-header' === $style ) : ?>
	<aside class="kk-pwa-banner" data-kk-pwa-button data-kk-pwa-button-style="above-header"<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="kk-shell kk-pwa-banner-inner">
			<div class="kk-pwa-banner-content">
				<img class="kk-pwa-banner-icon" src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-k.svg' ); ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
				<span class="kk-pwa-banner-text"><?php echo esc_html( $button_desc ); ?></span>
			</div>
			<div class="kk-pwa-banner-actions">
				<button type="button" class="kk-button kk-pwa-install" id="kk-pwa-install" data-kk-sw-url="<?php echo esc_url( $sw_url ); ?>" aria-label="<?php echo esc_attr( $button_text ); ?>">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $button_text ); ?></span>
				</button>
				<button type="button" class="kk-icon-button kk-pwa-dismiss" data-kk-pwa-dismiss aria-label="<?php esc_attr_e( 'Close', 'koperasikita' ); ?>">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</div>
	</aside>
<?php elseif ( in_array( $style, array( 'beside-clock', 'beside-weather' ), true ) ) : ?>
	<div class="kk-pwa-inline kk-pwa-inline--<?php echo esc_attr( $style ); ?>" data-kk-pwa-button data-kk-pwa-button-style="<?php echo esc_attr( $style ); ?>"<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<button type="button" class="kk-icon-button kk-pwa-inline-btn" id="kk-pwa-install" data-kk-sw-url="<?php echo esc_url( $sw_url ); ?>" aria-label="<?php echo esc_attr( $button_text ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'download', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="kk-pwa-inline-label"><?php echo esc_html( $button_text ); ?></span>
		</button>
	</div>
<?php else : ?>
	<aside class="kk-pwa-float kk-pwa-float--<?php echo esc_attr( $style ); ?>" data-kk-pwa-button data-kk-pwa-button-style="<?php echo esc_attr( $style ); ?>"<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php if ( 'float-bottom' === $style ) : ?>
			<div class="kk-pwa-float-content">
				<img class="kk-pwa-float-icon" src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-k.svg' ); ?>" alt="" width="24" height="24" loading="lazy" decoding="async">
				<span class="kk-pwa-float-desc"><?php echo esc_html( $button_desc ); ?></span>
			</div>
		<?php endif; ?>
		<button type="button" class="kk-button kk-pwa-install" id="kk-pwa-install" data-kk-sw-url="<?php echo esc_url( $sw_url ); ?>" aria-label="<?php echo esc_attr( $button_text ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php echo esc_html( $button_text ); ?></span>
		</button>
		<button type="button" class="kk-icon-button kk-pwa-dismiss" data-kk-pwa-dismiss aria-label="<?php esc_attr_e( 'Close', 'koperasikita' ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</aside>
<?php endif; ?>

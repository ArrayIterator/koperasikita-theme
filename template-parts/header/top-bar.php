<?php
/**
 * Top bar: live clock, weather slot.
 *
 * Search and the colour-scheme toggle live in the sticky nav bar,
 * not here.
 * The clock is server-rendered in the resolved time zone so the first
 * paint is correct, then main.ts keeps it ticking. In "device" mode the
 * server renders the site time and the script replaces it with the
 * visitor's local time on load.
 *
 * @package KoperasiKita
 */

if ( ! (bool) get_theme_mod( 'kk_topbar_enabled', true ) ) {
	return;
}

$show_clock = (bool) get_theme_mod( 'kk_clock_enabled', true );
$clock_html = '';

if ( $show_clock ) {
	$clock_mode = \KoperasiKita\Customizer\Customizer::sanitizeClockTimezone( get_theme_mod( 'kk_clock_timezone', 'site' ) );
	$clock_zone = wp_timezone();
	$clock_zone_name = $clock_zone->getName();

	if ( 'custom' === $clock_mode ) {
		$custom_zone = \KoperasiKita\Customizer\Customizer::sanitizeCustomTimezone( get_theme_mod( 'kk_clock_custom_timezone', 'Asia/Jakarta' ) );

		try {
			$clock_zone = new DateTimeZone( $custom_zone );
			$clock_zone_name = $custom_zone;
		} catch ( Exception $e ) {
			// Keep the site time zone when the stored zone is unusable.
		}
	}

	$clock_24h = (bool) get_theme_mod( 'kk_clock_24h', true );
	$clock_seconds = (bool) get_theme_mod( 'kk_clock_seconds', false );
	$clock_separator = \KoperasiKita\Customizer\Customizer::sanitizeClockSeparator( get_theme_mod( 'kk_clock_separator', 'colon' ) );
	$separator = 'dot' === $clock_separator ? '.' : ':';

	$now = new DateTimeImmutable( 'now', $clock_zone );
	$clock_format = ( $clock_24h ? 'H' : 'g' ) . $separator . 'i' . ( $clock_seconds ? $separator . 's' : '' ) . ( $clock_24h ? '' : ' A' );

	$clock_html = sprintf(
		'<time id="kk-clock" class="kk-topbar-clock" datetime="%s" data-mode="%s" data-timezone="%s" data-seconds="%s" data-24h="%s" data-separator="%s">%s</time>',
		esc_attr( $now->format( 'c' ) ),
		esc_attr( $clock_mode ),
		esc_attr( 'device' === $clock_mode ? '' : $clock_zone_name ),
		esc_attr( $clock_seconds ? '1' : '0' ),
		esc_attr( $clock_24h ? '1' : '0' ),
		esc_attr( $separator ),
		esc_html( $now->format( $clock_format ) )
	);
}
?>

<div class="kk-topbar">
	<div class="kk-shell kk-topbar-inner">
		<div class="kk-topbar-left">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaping.
			echo $clock_html;
			?>
			<?php
			if ( \KoperasiKita\Features\Pwa::isButtonPosition( 'beside-clock' ) ) {
				get_template_part( 'template-parts/pwa/install-button', null, array( 'style' => 'beside-clock' ) );
			}
			?>
		</div>

		<div class="kk-topbar-right">
			<?php if ( \KoperasiKita\Features\Features::isEnabled( 'weather' ) ) : ?>
				<div id="kk-topbar-weather" class="kk-topbar-weather" aria-live="polite"></div>
				<button type="button" class="kk-icon-button kk-weather-open" data-kk-weather-open aria-label="<?php esc_attr_e( 'Change weather location', 'koperasikita' ); ?>" aria-haspopup="dialog" aria-controls="kk-weather-picker">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'location', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			<?php endif; ?>

			<?php
			if ( \KoperasiKita\Features\Pwa::isButtonPosition( 'beside-weather' ) ) {
				get_template_part( 'template-parts/pwa/install-button', null, array( 'style' => 'beside-weather' ) );
			}
			?>
		</div>
	</div>
</div>

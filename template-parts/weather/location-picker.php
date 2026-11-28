<?php
/**
 * Weather location picker (centered modal + blurred backdrop).
 *
 * Lets the visitor pick the weather location: search by city name,
 * enter coordinates, use browser geolocation, or pick from the
 * quick-pick strip. The choice is per-visitor (localStorage) and the
 * default city is Padang. Rendered only when the weather module is
 * enabled; the script in src/ts/weather.ts owns the behaviour.
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'weather' ) ) {
	return;
}

$default      = \KoperasiKita\Features\Weather::defaultLocation();
?>

<div class="kk-weather-modal" id="kk-weather-picker" role="dialog" aria-modal="true" aria-labelledby="kk-weather-picker-title" hidden>
	<div class="kk-weather-modal-backdrop" data-kk-weather-close></div>
	<div class="kk-weather-modal-dialog">
		<button type="button" class="kk-icon-button kk-weather-modal-close" data-kk-weather-close aria-label="<?php esc_attr_e( 'Close location picker', 'koperasikita' ); ?>">
			<?php echo \KoperasiKita\Icons\Icon::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>

		<h2 class="kk-weather-modal-title" id="kk-weather-picker-title"><?php esc_html_e( 'Choose location', 'koperasikita' ); ?></h2>
		<p class="kk-weather-current" data-kk-weather-current>
			<?php
			/* translators: %s: city name. */
			printf( esc_html__( 'Current location: %s', 'koperasikita' ), esc_html( $default['name'] ) );
			?>
		</p>

		<div class="kk-weather-field">
			<label for="kk-weather-map-name"><?php esc_html_e( 'Location name', 'koperasikita' ); ?></label>
			<input type="text" id="kk-weather-map-name" data-kk-weather-map-name autocomplete="off">
		</div>

		<div class="kk-weather-field">
			<label for="kk-weather-search"><?php esc_html_e( 'Search for a city', 'koperasikita' ); ?></label>
			<input type="search" id="kk-weather-search" data-kk-weather-search autocomplete="off"
				placeholder="<?php esc_attr_e( 'Type a city name…', 'koperasikita' ); ?>" aria-describedby="kk-weather-status">
			<ul class="kk-weather-results" data-kk-weather-results aria-label="<?php esc_attr_e( 'Search results', 'koperasikita' ); ?>"></ul>
		</div>

		<p class="kk-weather-status" id="kk-weather-status" data-kk-weather-status role="status" aria-live="polite"></p>

		<button type="button" class="kk-weather-map-toggle" data-kk-weather-map-toggle aria-expanded="false">
			<?php esc_html_e( 'Show map', 'koperasikita' ); ?>
		</button>

		<div class="kk-weather-map-wrap" hidden>
			<p class="kk-weather-map-label"><?php esc_html_e( 'Map', 'koperasikita' ); ?></p>
			<div class="kk-weather-map" data-kk-weather-map></div>
			<p class="kk-weather-map-coords" data-kk-weather-map-coords></p>
			<p class="kk-weather-map-hint"><?php esc_html_e( 'Click the map to place the marker, or drag it.', 'koperasikita' ); ?></p>
		</div>

		<fieldset class="kk-weather-field kk-weather-coords">
			<legend><?php esc_html_e( 'Or enter coordinates', 'koperasikita' ); ?></legend>
			<div class="kk-weather-coords-inputs">
				<div>
					<label for="kk-weather-lat"><?php esc_html_e( 'Latitude', 'koperasikita' ); ?></label>
					<input type="number" id="kk-weather-lat" data-kk-weather-lat inputmode="decimal"
						min="-90" max="90" step="any" placeholder="-0.9471">
				</div>
				<div>
					<label for="kk-weather-lon"><?php esc_html_e( 'Longitude', 'koperasikita' ); ?></label>
					<input type="number" id="kk-weather-lon" data-kk-weather-lon inputmode="decimal"
						min="-180" max="180" step="any" placeholder="100.4172">
				</div>
			</div>
			<p class="kk-weather-error" data-kk-weather-coords-error role="alert" hidden></p>
			<button type="button" class="kk-button kk-button-ghost kk-weather-apply-coords" data-kk-weather-apply-coords>
				<?php esc_html_e( 'Use these coordinates', 'koperasikita' ); ?>
			</button>
		</fieldset>

		<button type="button" class="kk-button kk-button-ghost kk-weather-geolocate" data-kk-weather-geolocate>
			<?php echo \KoperasiKita\Icons\Icon::svg( 'location', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'Use my location', 'koperasikita' ); ?></span>
		</button>

		<div class="kk-weather-modal-actions">
			<button type="button" class="kk-weather-cancel" data-kk-weather-close>
				<?php esc_html_e( 'Cancel', 'koperasikita' ); ?>
			</button>
			<button type="button" class="kk-button kk-button-ghost" data-kk-weather-reset>
				<?php esc_html_e( 'Reset to default', 'koperasikita' ); ?>
			</button>
			<button type="button" class="kk-button" data-kk-weather-update>
				<?php esc_html_e( 'Update', 'koperasikita' ); ?>
			</button>
		</div>
	</div>
</div>

<?php
/**
 * The shared Pick Location popup for agenda events.
 *
 * Rendered once per page: by Agenda::renderPickLocationPopup() at the
 * end of the agenda editor screen, and by the [kk_agenda_submit] form
 * after the form itself. The popup stages its edits - opening copies
 * the picker's values in, "Use this location" copies them back, and
 * closing (Cancel, Escape, backdrop) discards them - so nothing is
 * saved until the editor saves the post or the contributor submits.
 *
 * The popup's own inputs carry no name attributes, so they are never
 * submitted: the real values live in the picker block's inputs
 * (kk_agenda_lat / kk_agenda_lng / kk_agenda_location_desc).
 *
 * The popup sits above the editor but below the admin menu (z-index
 * 9999) and the admin bar (99999): container 8999, backdrop 8998.
 *
 * @package KoperasiKita
 */

$locations = \KoperasiKita\Features\Agenda::savedLocations();
?>
<div class="kk-agenda-pick-location" id="kk-agenda-pick-location-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-agenda-pick-location-title">
	<div class="kk-agenda-pick-location-backdrop" data-kk-agenda-popup-close></div>
	<div class="kk-agenda-pick-location-dialog" role="document">
		<h2 id="kk-agenda-pick-location-title"><?php esc_html_e('Pick Location', 'koperasikita'); ?></h2>
		<?php if ($locations !== []) : ?>
		<p class="kk-agenda-popup-field">
			<label for="kk-agenda-saved-location"><?php esc_html_e('Saved location', 'koperasikita'); ?></label>
			<select id="kk-agenda-saved-location" data-kk-agenda-saved-location>
				<option value=""><?php esc_html_e('Choose a saved location…', 'koperasikita'); ?></option>
				<?php foreach ($locations as $location) : ?>
				<option
					value="<?php echo esc_attr($location['name']); ?>"
					data-lat="<?php echo esc_attr((string) $location['lat']); ?>"
					data-lng="<?php echo esc_attr((string) $location['lng']); ?>"
					data-address="<?php echo esc_attr($location['address']); ?>"
					data-desc="<?php echo esc_attr($location['description']); ?>"
				><?php echo esc_html($location['name']); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php endif; ?>
		<div class="kk-agenda-map" data-kk-agenda-popup-map></div>
		<p class="kk-agenda-hint"><?php esc_html_e('Click the map to place the pin, or drag the pin to move it. The coordinates are saved with the location.', 'koperasikita'); ?></p>
		<div class="kk-agenda-popup-coords">
			<p class="kk-agenda-popup-field">
				<label for="kk-agenda-popup-lat"><?php esc_html_e('Latitude', 'koperasikita'); ?></label>
				<input type="number" id="kk-agenda-popup-lat" data-kk-agenda-popup-lat step="any" min="-90" max="90" inputmode="decimal">
			</p>
			<p class="kk-agenda-popup-field">
				<label for="kk-agenda-popup-lng"><?php esc_html_e('Longitude', 'koperasikita'); ?></label>
				<input type="number" id="kk-agenda-popup-lng" data-kk-agenda-popup-lng step="any" min="-180" max="180" inputmode="decimal">
			</p>
		</div>
		<p class="kk-agenda-popup-field">
			<label for="kk-agenda-popup-desc"><?php esc_html_e('Location description', 'koperasikita'); ?></label>
			<input type="text" id="kk-agenda-popup-desc" data-kk-agenda-popup-desc maxlength="200">
		</p>
		<p class="kk-agenda-pick-location-actions">
			<button type="button" class="button button-primary" data-kk-agenda-popup-apply><?php esc_html_e('Use this location', 'koperasikita'); ?></button>
			<button type="button" class="button" data-kk-agenda-popup-close><?php esc_html_e('Cancel', 'koperasikita'); ?></button>
		</p>
	</div>
</div>

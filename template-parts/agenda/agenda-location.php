<?php
/**
 * Agenda event location ("location picker"): the venue with a map link.
 *
 * Expects the global $post to be an agenda item. Renders nothing when
 * the item has no stored location. The map link opens a Google Maps
 * search for the venue in a new tab.
 *
 * @package KoperasiKita
 */

$meta = \KoperasiKita\Features\Agenda::getMeta(get_the_ID());

if ($meta['location'] === '') {
    return;
}

$maps_url = 'https://www.google.com/maps/search/' . rawurlencode($meta['location']);
?>

<p class="kk-agenda-location">
	<span class="kk-agenda-location-icon" aria-hidden="true"><?php echo \KoperasiKita\Icons\Icon::svg('location', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<span class="kk-agenda-location-text"><?php echo esc_html($meta['location']); ?></span>
	<a class="kk-agenda-location-map" href="<?php echo esc_url($maps_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View map', 'koperasikita'); ?></a>
</p>

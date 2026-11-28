<?php
/**
 * Agenda event location ("location picker"): the venue with a map link,
 * plus the embedded map when the item carries a pin.
 *
 * Expects the global $post to be an agenda item. Renders nothing when
 * the item has neither a stored location nor coordinates. The map link
 * opens Google Maps at the venue's stored coordinates in a new tab and
 * is omitted entirely when the item has no coordinates; the embedded
 * map is rendered into the container by the agenda script.
 *
 * @package KoperasiKita
 */

$meta = \KoperasiKita\Features\Agenda::getMeta(get_the_ID());
$hasCoords = $meta['lat'] !== null && $meta['lng'] !== null;

if ($meta['location'] === '' && !$hasCoords) {
    return;
}

$maps_url = $hasCoords ? 'https://www.google.com/maps?q=' . $meta['lat'] . ',' . $meta['lng'] : '';
?>

<?php if ($meta['location'] !== '') : ?>
<p class="kk-agenda-location">
	<span class="kk-agenda-location-label"><?php esc_html_e('Location', 'koperasikita'); ?></span>
	<span class="kk-agenda-location-icon" aria-hidden="true"><?php echo \KoperasiKita\Icons\Icon::svg('location', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<span class="kk-agenda-location-text"><?php echo esc_html($meta['location']); ?></span>
	<?php if ($hasCoords) : ?>
	<a class="kk-agenda-location-map" href="<?php echo esc_url($maps_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View map', 'koperasikita'); ?></a>
	<?php endif; ?>
</p>
<?php endif; ?>

<?php if ($hasCoords) : ?>
<div class="kk-agenda-map" data-kk-agenda-map data-kk-agenda-lat="<?php echo esc_attr((string) $meta['lat']); ?>" data-kk-agenda-lng="<?php echo esc_attr((string) $meta['lng']); ?>" data-kk-agenda-label="<?php echo esc_attr($meta['location']); ?>" data-kk-agenda-desc="<?php echo esc_attr($meta['location_desc']); ?>"></div>
<?php endif; ?>

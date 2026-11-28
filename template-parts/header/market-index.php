<?php
/**
 * Market header index: the strip below the header.
 *
 * Expects $args['items'] as MarketPrice[] (only items with a real
 * price) and $args['snapshot'] as the latest updated timestamp (or
 * null). $args['caption'] is the snapshot caption text (label, day
 * and time, or both) as built by MarketHeaderIndex::captionText();
 * direct template use without it builds the caption from the
 * snapshot the same way. $args['cycle_interval'] is the seconds
 * between items when the strip cycles on small screens (7 by
 * default). Renders nothing when the list is empty - the caller
 * (MarketHeaderIndex::render) already guarantees that, this is a
 * second guard for direct template use. The strip has no
 * background of its own: the items sit directly on the page
 * background.
 *
 * @package KoperasiKita
 */

$items = isset($args['items']) && is_array($args['items']) ? array_values($args['items']) : [];
$snapshot = isset($args['snapshot']) && is_int($args['snapshot']) && $args['snapshot'] > 0 ? $args['snapshot'] : null;
$caption = isset($args['caption']) && is_string($args['caption'])
	? $args['caption']
	: \KoperasiKita\Features\MarketHeaderIndex::captionText($snapshot);
$cycleInterval = isset($args['cycle_interval']) && is_numeric($args['cycle_interval']) ? max(2, min(120, (int) $args['cycle_interval'])) : 7;

if ($items === []) {
	return;
}
?>
<div class="kk-market-header" role="region" aria-label="<?php esc_attr_e('Market index', 'koperasikita'); ?>">
	<div class="kk-shell kk-market-header-inner">
		<?php
		get_template_part('template-parts/markets/index-strip', null, [
			'items' => $items,
			'show_change' => true,
			'cycle' => true,
			'cycle_interval' => $cycleInterval,
		]);
		?>
		<?php if ($caption !== '') : ?>
			<span class="kk-market-header-snapshot"><?php echo esc_html($caption); ?></span>
		<?php endif; ?>
	</div>
</div>

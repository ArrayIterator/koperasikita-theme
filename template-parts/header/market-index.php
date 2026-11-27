<?php
/**
 * Market header index: the dark strip below the header.
 *
 * Expects $args['items'] as MarketPrice[] (only items with a real
 * price) and $args['snapshot'] as the latest updated timestamp (or
 * null). Renders nothing when the list is empty - the caller
 * (MarketHeaderIndex::render) already guarantees that, this is a
 * second guard for direct template use.
 *
 * @package KoperasiKita
 */

$items = isset($args['items']) && is_array($args['items']) ? array_values($args['items']) : [];
$snapshot = isset($args['snapshot']) && is_int($args['snapshot']) && $args['snapshot'] > 0 ? $args['snapshot'] : null;

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
		]);
		?>
		<?php if ($snapshot !== null) : ?>
			<span class="kk-market-header-snapshot"><?php echo esc_html(sprintf('%s · %s', __('Market Snapshot', 'koperasikita'), date_i18n('l H.i T', $snapshot))); ?></span>
		<?php endif; ?>
	</div>
</div>

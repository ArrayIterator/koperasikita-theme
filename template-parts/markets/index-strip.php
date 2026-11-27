<?php
/**
 * Markets index strip: the picked items as one flat horizontal
 * row - name, value and day-change chip per item, no market
 * grouping. The demo strip look: indices, currencies, gold
 * brands and Brent side by side, each item in picked order.
 *
 * Expects $args['items'] as an array of \KoperasiKita\Providers\
 * MarketPrice (only items with a real price - an item with no
 * price never reaches this template) and $args['show_change']
 * as bool. $args['cycle'] (bool, header strip only) marks the strip
 * for the mobile ticker-style rotation: the list carries its speed
 * and the first item renders active, so the first paint already
 * shows an item before the rotation script runs. The other
 * index-strip callers render the plain row.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$items = isset($args['items']) && is_array($args['items']) ? array_values($args['items']) : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];
$cycle = !empty($args['cycle']);
// The header strip cycles one item at a time on mobile, like the
// news ticker; data-speed is the seconds between items, from the
// strip's cycle interval setting (7 by default, not the ticker's
// 5, so the two rotations never step together).
$cycleInterval = isset($args['cycle_interval']) && is_numeric($args['cycle_interval']) ? max(2, min(120, (int) $args['cycle_interval'])) : 7;
$strip_attrs = $cycle ? ' id="kk-market-header-strip" data-speed="' . $cycleInterval . '"' : '';

if ($items === []) {
	return;
}
?>
<div class="kk-index-strip"<?php echo $strip_attrs; ?> role="list" aria-label="<?php esc_attr_e('Market index', 'koperasikita'); ?>">
	<?php foreach ($items as $index => $price) : ?>
		<span class="kk-index-item<?php echo $cycle && 0 === $index ? ' is-active' : ''; ?>" role="listitem">
			<span class="kk-index-name"><?php echo esc_html($price->label); ?></span>
			<span class="kk-index-value"><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></span>
			<?php if ($show_change && $price->change !== null) : ?>
				<span class="kk-index-change <?php echo $price->change >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>">
					<?php echo Icon::svg($price->change >= 0 ? 'trending-up' : 'trending-down', 12); ?>
					<?php echo esc_html(Markets::formatChange($price->change)); ?>
				</span>
			<?php endif; ?>
		</span>
	<?php endforeach; ?>
</div>

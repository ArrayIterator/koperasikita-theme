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
 * for the desktop marquee: the list carries its speed (seconds
 * per item) and the live-price data attributes. On mobile the
 * strip is a swipeable row instead. The other index-strip
 * callers render the plain row.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$items = isset($args['items']) && is_array($args['items']) ? array_values($args['items']) : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];
$cycle = !empty($args['cycle']);
// On mobile the header strip is a swipeable row (touch drag);
// on desktop it scrolls as a marquee. data-speed is the seconds
// per item in the marquee, from the strip's cycle interval
// setting (7 by default).
$cycleInterval = isset($args['cycle_interval']) && is_numeric($args['cycle_interval']) ? max(2, min(120, (int) $args['cycle_interval'])) : 7;
$strip_attrs = $cycle ? ' id="kk-market-header-strip" data-speed="' . $cycleInterval . '"' : '';

if ($items === []) {
	return;
}
?>
<div class="kk-index-strip"<?php echo $strip_attrs; ?> role="list" aria-label="<?php esc_attr_e('Market index', 'koperasikita'); ?>">
	<?php foreach ($items as $price) : ?>
		<span class="kk-index-item" role="listitem"<?php if ($cycle) : ?> data-kk-symbol="<?php echo esc_attr((string) $price->symbol); ?>" data-kk-market="<?php echo esc_attr($price->market); ?>" data-kk-currency="<?php echo esc_attr($price->currency); ?>"<?php endif; ?>>
			<span class="kk-index-name"><?php echo esc_html($price->label); ?></span>
			<span class="kk-index-value"<?php if ($cycle) : ?> data-kk-price<?php endif; ?>><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></span>
			<?php if ($show_change && $price->change !== null) : ?>
				<span class="kk-index-change <?php echo $price->change >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>"<?php if ($cycle) : ?> data-kk-change data-kk-icon-up="<?php echo esc_attr(Icon::svg('trending-up', 12)); ?>" data-kk-icon-down="<?php echo esc_attr(Icon::svg('trending-down', 12)); ?>"<?php endif; ?>>
					<?php echo Icon::svg($price->change >= 0 ? 'trending-up' : 'trending-down', 12); ?>
					<?php if ($cycle) : ?><span class="kk-index-change-text" data-kk-change-text><?php echo esc_html(Markets::formatChange($price->change)); ?></span><?php else : ?><?php echo esc_html(Markets::formatChange($price->change)); ?><?php endif; ?>
				</span>
			<?php endif; ?>
		</span>
	<?php endforeach; ?>
</div>

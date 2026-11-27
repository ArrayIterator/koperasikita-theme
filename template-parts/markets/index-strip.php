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
 * as bool. No data renders nothing, never an invented number.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$items = isset($args['items']) && is_array($args['items']) ? array_values($args['items']) : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];

if ($items === []) {
	return;
}
?>
<div class="kk-index-strip" role="list" aria-label="<?php esc_attr_e('Market index', 'koperasikita'); ?>">
	<?php foreach ($items as $price) : ?>
		<span class="kk-index-item" role="listitem">
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

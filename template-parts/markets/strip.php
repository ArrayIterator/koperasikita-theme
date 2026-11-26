<?php
/**
 * Markets strip: gold spot, stocks and crypto in one compact list.
 *
 * Expects $args['markets'] as array<string, array{label: string, prices: array<string, \KoperasiKita\Providers\MarketPrice>}>
 * and $args['show_change'] as bool. A market with no prices renders a
 * "no data" line for that market; when no market has any data the
 * whole strip renders nothing.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;

$markets = isset($args['markets']) && is_array($args['markets']) ? $args['markets'] : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];

$has_data = false;
foreach ($markets as $market) {
	if (!empty($market['prices'])) {
		$has_data = true;
		break;
	}
}

if ($markets === [] || !$has_data) {
	// No data: render nothing (no empty-state text). The strip is
	// replaced by a widget later.
	return;
}
?>
<div class="kk-markets-strip">
	<?php foreach ($markets as $market) : ?>
		<?php $prices = is_array($market['prices'] ?? null) ? $market['prices'] : []; ?>
		<section class="kk-market-group" aria-label="<?php echo esc_attr($market['label']); ?>">
			<h3 class="kk-market-group-title"><?php echo esc_html($market['label']); ?></h3>
			<?php if ($prices === []) : ?>
				<p class="kk-widget-empty"><?php esc_html_e('No data yet.', 'koperasikita'); ?></p>
			<?php else : ?>
				<ul class="kk-market-list">
					<?php foreach ($prices as $symbol => $price) : ?>
						<li class="kk-market-row">
							<span class="kk-market-symbol"><?php echo esc_html($price->label); ?></span>
							<span class="kk-market-price"><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></span>
							<?php if ($show_change && $price->change !== null) : ?>
								<span class="kk-market-change <?php echo $price->change >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>">
									<?php echo esc_html(Markets::formatChange($price->change)); ?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>

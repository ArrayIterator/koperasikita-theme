<?php
/**
 * Markets marquee: a continuously scrolling ticker of market groups.
 *
 * Each market group is one marquee item; the set is rendered twice so
 * the CSS translateX(-50%) loop is seamless. The item width follows
 * the --kk-markets-visible variable (how many items show at once on
 * desktop, 1-4). Pauses on hover/focus and when the tab is hidden;
 * disabled entirely under prefers-reduced-motion. Live-price refresh
 * keeps working: each item keeps its data-kk-market-prices container
 * and per-row data-kk-symbol / data-kk-price / data-kk-change
 * attributes, and the refresh updates both copies of a row.
 *
 * Expects $args['markets'] as array<string, array{label: string, prices: array<string, \KoperasiKita\Providers\MarketPrice>}>
 * and $args['show_change'] as bool and $args['visible'] as int.
 * A market with no prices is skipped; when no market has any data
 * the template renders nothing (same as the strip).
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;

$markets = isset($args['markets']) && is_array($args['markets']) ? $args['markets'] : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];
$visible = isset($args['visible']) ? max(1, min(4, (int) $args['visible'])) : 1;

$items = [];
foreach ($markets as $marketId => $market) {
	if (!empty($market['prices'])) {
		$items[(string) $marketId] = $market;
	}
}

if ($items === []) {
	return;
}

// Each set must be at least as wide as the marquee viewport, or the
// -50% loop leaves a gap. Repeat the items inside a set until it
// holds at least `visible` items (a ticker repeats anyway).
$repeat = max(1, (int) ceil($visible / max(1, count($items))));
$set_items = [];
for ($i = 0; $i < $repeat; $i++) {
	foreach ($items as $marketId => $market) {
		$set_items[] = [$marketId, $market];
	}
}
?>
<div class="kk-markets-marquee" data-kk-markets-marquee style="--kk-markets-visible: <?php echo esc_attr((string) $visible); ?>;">
	<div class="kk-markets-marquee-track">
		<?php for ($copy = 0; $copy < 2; $copy++) : ?>
			<div class="kk-markets-marquee-set" <?php echo $copy === 1 ? 'aria-hidden="true"' : ''; ?>>
				<?php foreach ($set_items as [$marketId, $market]) : ?>
					<?php $prices = is_array($market['prices'] ?? null) ? $market['prices'] : []; ?>
					<?php $client = is_array($market['client'] ?? null) ? $market['client'] : []; ?>
					<section
						class="kk-market-group kk-markets-marquee-item"
						aria-label="<?php echo esc_attr($market['label']); ?>"
						data-kk-market-prices
						data-kk-market="<?php echo esc_attr((string) $marketId); ?>"
						data-kk-provider="<?php echo esc_attr((string) ($client['provider'] ?? 'manual')); ?>"
						<?php if (!empty($client['clientApiUrl'])) : ?>
							data-kk-client-api="<?php echo esc_attr((string) $client['clientApiUrl']); ?>"
						<?php endif; ?>
					>
						<h3 class="kk-market-group-title"><?php echo esc_html($market['label']); ?></h3>
						<ul class="kk-market-list">
							<?php foreach ($prices as $symbol => $price) : ?>
								<li class="kk-market-row" data-kk-symbol="<?php echo esc_attr((string) $symbol); ?>">
									<span class="kk-market-symbol"><?php echo esc_html($price->label); ?></span>
									<span class="kk-market-price" data-kk-price><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></span>
									<?php if ($show_change && $price->change !== null) : ?>
										<span class="kk-market-change <?php echo $price->change >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>" data-kk-change>
											<?php echo esc_html(Markets::formatChange($price->change)); ?>
										</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endforeach; ?>
			</div>
		<?php endfor; ?>
	</div>
</div>

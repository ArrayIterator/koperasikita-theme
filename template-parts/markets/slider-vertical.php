<?php
/**
 * Markets vertical slider: one slide per market group, auto-scrolling
 * vertically - the markets counterpart of the gold slider
 * (template-parts/gold/slider.php). One slide per market group (gold
 * spot, stocks, crypto) so the live-price refresh keeps working: each
 * slide keeps its own data-kk-market-prices container and per-row
 * data-kk-symbol / data-kk-price / data-kk-change attributes.
 *
 * Expects $args['markets'] as array<string, array{label: string, prices: array<string, \KoperasiKita\Providers\MarketPrice>}>
 * and $args['show_change'] as bool. A market with no prices is skipped;
 * when no market has any data the template renders nothing (same as
 * the strip). A single slide has no controls and no timer.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$markets = isset($args['markets']) && is_array($args['markets']) ? $args['markets'] : [];
$show_change = !isset($args['show_change']) || (bool) $args['show_change'];

// Only groups with prices become slides.
$slides = [];
foreach ($markets as $marketId => $market) {
	if (!empty($market['prices'])) {
		$slides[(string) $marketId] = $market;
	}
}

if ($slides === []) {
	return;
}

$single = count($slides) === 1;
?>
<div class="kk-markets-slider<?php echo $single ? ' is-single' : ''; ?>" data-kk-markets-slider data-interval="5000">
	<div class="kk-widget-scroll">
		<ul class="kk-markets-slides">
			<?php $index = 0; ?>
			<?php foreach ($slides as $marketId => $market) : ?>
				<?php $prices = is_array($market['prices'] ?? null) ? $market['prices'] : []; ?>
				<?php $client = is_array($market['client'] ?? null) ? $market['client'] : []; ?>
				<li class="kk-markets-slide<?php echo $index === 0 ? ' is-active' : ''; ?>" data-kk-markets-slide>
					<section
						class="kk-market-group"
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
								<li class="kk-market-row" data-kk-symbol="<?php echo esc_attr((string) $symbol); ?>" data-kk-currency="<?php echo esc_attr($price->currency); ?>">
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
				</li>
				<?php $index++; ?>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php if (!$single) : ?>
		<div class="kk-widget-controls">
			<button type="button" class="kk-widget-nav" data-kk-markets-prev aria-label="<?php esc_attr_e('Previous market', 'koperasikita'); ?>">
				<?php echo Icon::svg('prev', 18); ?>
			</button>
			<div class="kk-widget-dots" role="tablist" aria-label="<?php esc_attr_e('Markets', 'koperasikita'); ?>">
				<?php $index = 0; ?>
				<?php foreach ($slides as $marketId => $market) : ?>
					<button
						type="button"
						class="kk-widget-dot<?php echo $index === 0 ? ' is-active' : ''; ?>"
						data-kk-markets-dot="<?php echo esc_attr((string) $index); ?>"
						role="tab"
						aria-label="<?php echo esc_attr($market['label']); ?>"
						aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>"
					></button>
					<?php $index++; ?>
				<?php endforeach; ?>
			</div>
			<button type="button" class="kk-widget-nav" data-kk-markets-next aria-label="<?php esc_attr_e('Next market', 'koperasikita'); ?>">
				<?php echo Icon::svg('next', 18); ?>
			</button>
			<button
				type="button"
				class="kk-widget-nav kk-widget-toggle"
				data-kk-markets-toggle
				aria-pressed="false"
				aria-label="<?php esc_attr_e('Pause markets', 'koperasikita'); ?>"
			>
				<span class="kk-widget-toggle-pause"><?php echo Icon::svg('pause', 18); ?></span>
				<span class="kk-widget-toggle-play" hidden><?php echo Icon::svg('play', 18); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>

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
$layout = isset($args['layout']) && is_string($args['layout']) ? $args['layout'] : 'list';
$visible = isset($args['visible']) ? max(1, min(4, (int) $args['visible'])) : 1;
$layout_class = in_array($layout, ['grid', 'slider-horizontal', 'strip'], true)
	? ' kk-markets-strip--' . $layout
	: '';
$visible_style = $layout === 'slider-horizontal'
	? ' style="--kk-markets-visible: ' . esc_attr((string) $visible) . ';"'
	: '';

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
<div class="kk-markets-strip<?php echo esc_attr($layout_class); ?>"<?php echo $visible_style; ?>>
	<?php foreach ($markets as $marketId => $market) : ?>
		<?php $prices = is_array($market['prices'] ?? null) ? $market['prices'] : []; ?>
		<?php $client = is_array($market['client'] ?? null) ? $market['client'] : []; ?>
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
			<?php if ($prices === []) : ?>
				<p class="kk-widget-empty"><?php esc_html_e('No data yet.', 'koperasikita'); ?></p>
			<?php else : ?>
				<ul class="kk-market-list">
					<?php foreach ($prices as $symbol => $price) : ?>
						<li class="kk-market-row" data-kk-symbol="<?php echo esc_attr((string) $symbol); ?>"<?php echo $price->provider !== '' ? ' data-kk-provider="' . esc_attr($price->provider) . '"' : ''; ?>>
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
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>

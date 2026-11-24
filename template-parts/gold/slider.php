<?php
/**
 * Gold slider: one slide per priced brand, auto-scrolling vertically.
 *
 * Expects $args['brands'] as array<string, \KoperasiKita\Features\MarketPrice>
 * keyed by brand slug. Brands without a saved snapshot price are never
 * passed in here — see Gold::brandPrices(). When the array is empty this
 * template renders an honest empty state and no slider markup at all, so
 * the frontend script has nothing to initialise.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$brands = isset($args['brands']) && is_array($args['brands']) ? $args['brands'] : [];

if ($brands === []) {
	?>
	<p class="kk-widget-empty"><?php esc_html_e('Gold prices are not available yet.', 'koperasikita'); ?></p>
	<?php
	return;
}

$single = count($brands) === 1;
?>
<div class="kk-gold-slider<?php echo $single ? ' is-single' : ''; ?>" data-kk-gold-slider data-interval="5000">
	<div class="kk-widget-scroll">
		<ul class="kk-gold-slides">
			<?php $index = 0; ?>
			<?php foreach ($brands as $slug => $price) : ?>
				<li class="kk-gold-slide<?php echo $index === 0 ? ' is-active' : ''; ?>" data-kk-gold-slide>
					<p class="kk-gold-brand"><?php echo esc_html($price->label); ?></p>
					<p class="kk-gold-price"><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></p>
					<p class="kk-gold-meta">
						<span><?php esc_html_e('per gram', 'koperasikita'); ?></span>
						<?php if ($price->change !== null) : ?>
							<span class="kk-market-change <?php echo $price->change >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>">
								<?php echo esc_html(Markets::formatChange($price->change)); ?>
							</span>
						<?php endif; ?>
					</p>
					<?php if ($price->updated > 0) : ?>
						<p class="kk-gold-updated">
							<?php
							/* translators: %s: date the price was last updated. */
							printf(esc_html__('Updated %s', 'koperasikita'), esc_html(date_i18n(get_option('date_format'), $price->updated)));
							?>
						</p>
					<?php endif; ?>
				</li>
				<?php $index++; ?>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php if (!$single) : ?>
		<div class="kk-widget-controls">
			<button type="button" class="kk-widget-nav" data-kk-gold-prev aria-label="<?php esc_attr_e('Previous brand', 'koperasikita'); ?>">
				<?php echo Icon::svg('prev', 18); ?>
			</button>
			<div class="kk-widget-dots" role="tablist" aria-label="<?php esc_attr_e('Gold brands', 'koperasikita'); ?>">
				<?php $index = 0; ?>
				<?php foreach ($brands as $slug => $price) : ?>
					<button
						type="button"
						class="kk-widget-dot<?php echo $index === 0 ? ' is-active' : ''; ?>"
						data-kk-gold-dot="<?php echo esc_attr((string) $index); ?>"
						role="tab"
						aria-label="<?php echo esc_attr($price->label); ?>"
						aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>"
					></button>
					<?php $index++; ?>
				<?php endforeach; ?>
			</div>
			<button type="button" class="kk-widget-nav" data-kk-gold-next aria-label="<?php esc_attr_e('Next brand', 'koperasikita'); ?>">
				<?php echo Icon::svg('next', 18); ?>
			</button>
			<button
				type="button"
				class="kk-widget-nav kk-widget-toggle"
				data-kk-gold-toggle
				aria-pressed="false"
				aria-label="<?php esc_attr_e('Pause gold prices', 'koperasikita'); ?>"
			>
				<span class="kk-widget-toggle-pause"><?php echo Icon::svg('pause', 18); ?></span>
				<span class="kk-widget-toggle-play" hidden><?php echo Icon::svg('play', 18); ?></span>
			</button>
		</div>
	<?php endif; ?>
</div>

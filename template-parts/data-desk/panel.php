<?php
/**
 * Data Desk panel: one market symbol as a chart + stats panel.
 *
 * Expects $args['data'] as the DataDesk::panelData() array,
 * $args['palette'] as a DataDesk palette id and $args['days'] as the
 * chart window. The kicker reads "MEJA DATA". The title defaults to
 * "Harga Emas {label}, {N} Hari Terakhir" for gold and
 * "Harga {label}, {N} Hari Terakhir" for stocks; a widget title
 * overrides it. The right column holds three stats: the price with
 * its day change, the buyback with its spread (gold only), and the
 * weekly performance with its up-streak. A symbol with no usable
 * history gets a placeholder chart and honest empty stats instead
 * of invented numbers.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Markets;

$data = isset($args['data']) && is_array($args['data']) ? $args['data'] : null;
$palette = DataDesk::sanitizePalette(isset($args['palette']) ? (string) $args['palette'] : DataDesk::DEFAULT_PALETTE);
$days = isset($args['days']) ? max(3, min(30, (int) $args['days'])) : DataDesk::DEFAULT_DAYS;
// Preview renders (templates page, widget forms) pass 'preview' =>
// true: the data is a fixed sample series, never live prices, and
// the badge says so.
$preview = !empty($args['preview']);

if ($data === null) {
	return;
}

$isGold = ($data['market'] ?? '') === 'gold';
$label = (string) ($data['label'] ?? '');
$price = isset($data['price']) && $data['price'] !== null ? (float) $data['price'] : null;
$currency = (string) ($data['currency'] ?? 'IDR');
$points = is_array($data['points'] ?? null) ? $data['points'] : [];
$hasHistory = !empty($data['has_history']) && $points !== [];

// The widget title travels in $args['title'] when the widget set one;
// otherwise the automatic title names the symbol and the window.
$title = isset($args['title']) && trim((string) $args['title']) !== '' ? (string) $args['title'] : '';
if ($title === '') {
	$title = $isGold
		/* translators: %1$s: brand label, %2$d: days in the chart. */
		? sprintf(__('Harga Emas %1$s, %2$d Hari Terakhir', 'koperasikita'), $label, $days)
		: sprintf(
			/* translators: %1$s: symbol label, %2$d: days in the chart. */
			__('Harga %1$s, %2$d Hari Terakhir', 'koperasikita'),
			$label,
			$days
		);
}

$subtitle = '';
if ($hasHistory && !empty($data['date_range'])) {
	$subtitle = $isGold
		/* translators: %s: date range, e.g. 15–21 November 2026. */
		? sprintf(__('Harga jual per gram · %s', 'koperasikita'), $data['date_range'])
		: sprintf(
			/* translators: %s: date range. */
			__('Harga penutupan · %s', 'koperasikita'),
			$data['date_range']
		);
} else {
	$subtitle = $isGold
		? __('Harga jual per gram · riwayat belum tersedia.', 'koperasikita')
		: __('Harga terkini · riwayat belum tersedia.', 'koperasikita');
}

// Chart geometry (viewBox units, scales to the container).
$chartW = 560;
$chartH = 260;
$padL = 48;
$padR = 16;
$padT = 28;
$padB = 32;
$plotW = $chartW - $padL - $padR;
$plotH = $chartH - $padT - $padB;

$coords = [];
$minLabel = '';
$maxLabel = '';
if ($hasHistory) {
	$prices = array_column($points, 'price');
	$min = min($prices);
	$max = max($prices);
	$span = $max - $min;
	$pad = $span > 0 ? $span * 0.15 : $max * 0.05;
	$min -= $pad;
	$max += $pad;
	$count = count($points);

	foreach ($points as $i => $point) {
		$x = $count > 1 ? $padL + ($i / ($count - 1)) * $plotW : $padL + $plotW / 2;
		$y = $padT + (1 - ((float) $point['price'] - $min) / max(1.0, $max - $min)) * $plotH;
		$coords[] = ['x' => $x, 'y' => $y, 'price' => (float) $point['price'], 'short' => (string) $point['short']];
	}

	// Axis labels in thousands of rupiah (2.412 = Rp 2.412.000).
	$minLabel = number_format_i18n($min / 1000, 0);
	$maxLabel = number_format_i18n($max / 1000, 0);
}

$polyline = implode(' ', array_map(static fn(array $c): string => number_format($c['x'], 1, '.', '') . ',' . number_format($c['y'], 1, '.', ''), $coords));
?>

<section class="kk-data-desk-panel kk-data-desk-panel--<?php echo esc_attr($palette); ?>">
	<?php if ($preview) : ?>
		<p class="kk-data-desk-preview-badge"><?php esc_html_e('Preview — sample data', 'koperasikita'); ?></p>
	<?php endif; ?>
	<header class="kk-data-desk-head">
		<p class="kk-data-desk-kicker"><?php esc_html_e('Meja Data', 'koperasikita'); ?></p>
		<h2 class="kk-data-desk-title"><?php echo esc_html($title); ?></h2>
		<p class="kk-data-desk-subtitle"><?php echo esc_html($subtitle); ?></p>
	</header>
	<div class="kk-data-desk-body">
		<div class="kk-data-desk-chart">
			<?php if ($hasHistory) : ?>
				<svg viewBox="0 0 <?php echo (int) $chartW; ?> <?php echo (int) $chartH; ?>" width="100%" role="img" aria-label="<?php echo esc_attr($title); ?>">
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) $padT; ?>" x2="<?php echo (int) $padL; ?>" y2="<?php echo (int) ($chartH - $padB); ?>" class="kk-data-desk-axis" />
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) ($chartH - $padB); ?>" x2="<?php echo (int) ($chartW - $padR); ?>" y2="<?php echo (int) ($chartH - $padB); ?>" class="kk-data-desk-axis" />
					<text x="<?php echo (int) ($padL - 8); ?>" y="<?php echo (int) ($padT + 4); ?>" text-anchor="end" class="kk-data-desk-axis-label"><?php echo esc_html($maxLabel); ?></text>
					<text x="<?php echo (int) ($padL - 8); ?>" y="<?php echo (int) ($chartH - $padB); ?>" text-anchor="end" class="kk-data-desk-axis-label"><?php echo esc_html($minLabel); ?></text>
					<polyline fill="none" points="<?php echo esc_attr($polyline); ?>" class="kk-data-desk-line" />
					<?php foreach ($coords as $c) : ?>
						<circle cx="<?php echo esc_attr(number_format($c['x'], 1, '.', '')); ?>" cy="<?php echo esc_attr(number_format($c['y'], 1, '.', '')); ?>" r="5" class="kk-data-desk-dot" />
						<text x="<?php echo esc_attr(number_format($c['x'], 1, '.', '')); ?>" y="<?php echo esc_attr(number_format($c['y'] - 12, 1, '.', '')); ?>" text-anchor="middle" class="kk-data-desk-point-label"><?php echo esc_html(number_format_i18n($c['price'] / 1000, 0)); ?></text>
						<text x="<?php echo esc_attr(number_format($c['x'], 1, '.', '')); ?>" y="<?php echo (int) ($chartH - 8); ?>" text-anchor="middle" class="kk-data-desk-axis-label"><?php echo esc_html($c['short']); ?></text>
					<?php endforeach; ?>
				</svg>
			<?php else : ?>
				<svg viewBox="0 0 <?php echo (int) $chartW; ?> <?php echo (int) $chartH; ?>" width="100%" role="img" aria-label="<?php esc_attr_e('Price history not available yet', 'koperasikita'); ?>">
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) ($chartH / 2); ?>" x2="<?php echo (int) ($chartW - $padR); ?>" y2="<?php echo (int) ($chartH / 2); ?>" class="kk-data-desk-line kk-data-desk-line--placeholder" />
				</svg>
				<p class="kk-data-desk-placeholder"><?php esc_html_e('Riwayat harga belum tersedia.', 'koperasikita'); ?></p>
			<?php endif; ?>
		</div>
		<div class="kk-data-desk-stats">
			<div class="kk-data-desk-stat">
				<?php
				$stat1Label = $isGold
					/* translators: %s: brand label. */
					? sprintf(__('Harga Jual %s / Gram', 'koperasikita'), $label)
					/* translators: %s: symbol label. */
					: sprintf(__('Harga %s', 'koperasikita'), $label);
				?>
				<p class="kk-data-desk-stat-label"><?php echo esc_html($stat1Label); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo $price !== null ? esc_html(Markets::formatPrice($price, $currency)) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note">
					<?php if (isset($data['change']) && $data['change'] !== null && isset($data['change_abs']) && $data['change_abs'] !== null) : ?>
						<span class="kk-market-change <?php echo (float) $data['change'] >= 0 ? 'kk-market-change-up' : 'kk-market-change-down'; ?>">
							<?php
							echo (float) $data['change'] >= 0 ? '&uarr; ' : '&darr; ';
							echo esc_html(Markets::formatPrice(abs((float) $data['change_abs']), $currency));
							?>
						</span>
						<?php
						/* translators: %s: day change in percent. */
						printf(esc_html__('dari kemarin (%s)', 'koperasikita'), esc_html(Markets::formatChange((float) $data['change'])));
						?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</p>
			</div>
			<div class="kk-data-desk-stat">
				<p class="kk-data-desk-stat-label"><?php esc_html_e('Buyback / Gram', 'koperasikita'); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo isset($data['buyback']) && $data['buyback'] !== null ? esc_html(Markets::formatPrice((float) $data['buyback'], $currency)) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note">
					<?php if (isset($data['spread']) && $data['spread'] !== null) : ?>
						<?php
						/* translators: %s: sell-buyback spread in percent. */
						printf(esc_html__('Spread jual–buyback %s', 'koperasikita'), esc_html(number_format_i18n((float) $data['spread'], 1) . '%'));
						?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</p>
			</div>
			<div class="kk-data-desk-stat">
				<p class="kk-data-desk-stat-label"><?php esc_html_e('Kinerja Sepekan', 'koperasikita'); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo isset($data['week_change']) && $data['week_change'] !== null ? esc_html(Markets::formatChange((float) $data['week_change'])) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note">
					<?php if (isset($data['streak']) && (int) $data['streak'] > 0) : ?>
						<?php
						/* translators: %d: consecutive up days. (Plain __() — the theme's po2mo has no plural support.) */
						printf(esc_html__('%d hari beruntun di zona hijau', 'koperasikita'), (int) $data['streak']);
						?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</p>
			</div>
		</div>
	</div>
</section>

<?php
/**
 * Data Desk panel: one market symbol as a chart + stats panel.
 *
 * Expects $args['data'] as the DataDesk::panelData() array,
 * $args['palette'] as a DataDesk palette id and $args['days'] as the
 * chart window. The kicker defaults to "Meja Data" and can be
 * overridden per template via $args['kicker']. The title defaults to
 * "Harga Emas {label}, {N} Hari Terakhir" for gold and
 * "Harga {label}, {N} Hari Terakhir" for stocks; a widget title
 * overrides it. The right column holds three stats: the price with
 * its day change, the buyback with its spread (gold only), and the
 * weekly performance with its up-streak. Each stat's label and
 * sub-line can be overridden per template via $args['stats']
 * (block => ['label', 'sub'], empty = the computed text), and a
 * block can be hidden via $args['hidden_blocks'] — hidden blocks
 * are not rendered at all, except in the template editor's preview
 * ($args['editable']), where they stay visible, dimmed, with an eye
 * toggle to bring them back. The date-range line under the title
 * can be overridden per template via $args['date_line'] (empty =
 * the computed line); in the editor's preview it is inline-editable
 * like the kicker/title. A symbol with no usable history gets a
 * placeholder chart and honest empty stats instead of invented
 * numbers.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Markets;
use KoperasiKita\Icons\Icon;

$data = isset($args['data']) && is_array($args['data']) ? $args['data'] : null;
$palette = DataDesk::sanitizePalette(isset($args['palette']) ? (string) $args['palette'] : DataDesk::DEFAULT_PALETTE);
$days = isset($args['days']) ? max(3, min(30, (int) $args['days'])) : DataDesk::DEFAULT_DAYS;
// Preview renders (templates page, widget forms) pass 'preview' =>
// true: the data is a fixed sample series, never live prices, and
// the badge says so.
$preview = !empty($args['preview']);
// The template editor's preview (editable) renders the stat
// blocks with their eye toggles + edit hooks; every other render
// (frontend, widget previews) does not.
$editable = $preview && !empty($args['editable']);
$stats = isset($args['stats']) && is_array($args['stats']) ? $args['stats'] : [];
$hiddenBlocks = isset($args['hidden_blocks']) && is_array($args['hidden_blocks'])
	? array_values(array_intersect(array_map('sanitize_key', $args['hidden_blocks']), DataDesk::STAT_BLOCKS))
	: [];

if ($data === null) {
	return;
}

$isGold = ($data['market'] ?? '') === 'gold';
$label = (string) ($data['label'] ?? '');
$price = isset($data['price']) && $data['price'] !== null ? (float) $data['price'] : null;
$currency = (string) ($data['currency'] ?? 'IDR');
$points = is_array($data['points'] ?? null) ? $data['points'] : [];
$hasHistory = !empty($data['has_history']) && $points !== [];

// A saved template kicker overrides the default; empty means
// "Meja Data" (uppercased by CSS).
$kicker = isset($args['kicker']) && trim((string) $args['kicker']) !== '' ? (string) $args['kicker'] : '';

// The widget title travels in $args['title'] when the widget set one;
// otherwise the automatic title names the symbol and the window.
// A saved template title may use {label} (symbol label) and {day}
// (chart window in days) placeholders — see DataDesk::replaceTitleTokens().
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
} else {
	$title = DataDesk::replaceTitleTokens($title, $label, $days);
}

// A saved template date line overrides the computed range
// line; empty means the computed text below (literal text, no
// {label}/{day} tokens, like the stat label overrides).
$dateLineOverride = isset($args['date_line']) ? sanitize_text_field((string) $args['date_line']) : '';
$subtitle = '';
if ($dateLineOverride !== '') {
	$subtitle = $dateLineOverride;
} elseif ($hasHistory && !empty($data['date_range'])) {
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
		<p class="kk-data-desk-kicker"><?php echo $kicker !== '' ? esc_html($kicker) : esc_html__('Meja Data', 'koperasikita'); ?></p>
		<h2 class="kk-data-desk-title"><?php echo esc_html($title); ?></h2>
		<p class="kk-data-desk-subtitle"<?php echo $editable ? ' data-kk-date-line' : ''; ?>><?php echo esc_html($subtitle); ?></p>
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
		<?php
		// Per-block text: a stored override wins, empty means the
		// computed text. A sub-line override is plain text; without
		// one the block renders its computed note markup (day
		// change, spread, streak) as before — so the $*Sub values
		// are null when there is no override.
		$statOverride = static function (string $key, string $field) use ($stats): string {
			$row = isset($stats[$key]) && is_array($stats[$key]) ? $stats[$key] : [];
			return isset($row[$field]) ? sanitize_text_field((string) $row[$field]) : '';
		};
		$sellLabelOverride = $statOverride('sell', 'label');
		$sellLabel = $sellLabelOverride !== '' ? $sellLabelOverride : ($isGold
			/* translators: %s: brand label. */
			? sprintf(__('Harga Jual %s / Gram', 'koperasikita'), $label)
			/* translators: %s: symbol label. */
			: sprintf(__('Harga %s', 'koperasikita'), $label));
		$sellSubOverride = $statOverride('sell', 'sub');
		$sellSub = $sellSubOverride !== '' ? $sellSubOverride : null;
		$buybackLabelOverride = $statOverride('buyback', 'label');
		$buybackLabel = $buybackLabelOverride !== '' ? $buybackLabelOverride : __('Buyback / Gram', 'koperasikita');
		$buybackSubOverride = $statOverride('buyback', 'sub');
		$buybackSub = $buybackSubOverride !== '' ? $buybackSubOverride : null;
		$performanceLabelOverride = $statOverride('performance', 'label');
		$performanceLabel = $performanceLabelOverride !== '' ? $performanceLabelOverride : __('Kinerja Sepekan', 'koperasikita');
		$performanceSubOverride = $statOverride('performance', 'sub');
		$performanceSub = $performanceSubOverride !== '' ? $performanceSubOverride : null;
		$hiddenSell = in_array('sell', $hiddenBlocks, true);
		$hiddenBuyback = in_array('buyback', $hiddenBlocks, true);
		$hiddenPerformance = in_array('performance', $hiddenBlocks, true);
		// The eye toggle (template editor only): hides the block on
		// the frontend, dims it in the editor so it can be
		// un-hidden. The icon swaps on the next preview refresh
		// (admin.ts), after the toggle has dimmed the block live.
		$statToggle = static function (string $key, bool $hidden) use ($editable): string {
			if (!$editable) {
				return '';
			}
			$label = $hidden ? __('Show this stat', 'koperasikita') : __('Hide this stat', 'koperasikita');
			$icon = $hidden ? Icon::svg('eye-off', 16) : Icon::svg('eye', 16);
			return sprintf(
				'<button type="button" class="kk-data-desk-stat-toggle" data-kk-stat-toggle="%s" aria-pressed="%s" title="%s" aria-label="%s">%s</button>',
				esc_attr($key),
				$hidden ? 'true' : 'false',
				esc_attr($label),
				esc_attr($label),
				$icon
			);
		};
		?>
		<div class="kk-data-desk-stats">
			<?php if ($editable || !$hiddenSell) : ?>
			<div class="kk-data-desk-stat<?php echo $hiddenSell ? ' kk-data-desk-stat--hidden' : ''; ?>"<?php echo $editable ? ' data-kk-stat-block="sell"' : ''; ?>>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the toggle is built from escaped values + the theme's own icon SVG.
				echo $statToggle('sell', $hiddenSell);
				?>
				<p class="kk-data-desk-stat-label"<?php echo $editable ? ' data-kk-stat-field="sell-label"' : ''; ?>><?php echo esc_html($sellLabel); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo $price !== null ? esc_html(Markets::formatPrice($price, $currency)) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note"<?php echo $editable ? ' data-kk-stat-field="sell-sub"' : ''; ?>>
					<?php if ($sellSub !== null) : ?>
						<?php echo esc_html($sellSub); ?>
					<?php elseif (isset($data['change']) && $data['change'] !== null && isset($data['change_abs']) && $data['change_abs'] !== null) : ?>
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
			<?php endif; ?>
			<?php if ($editable || !$hiddenBuyback) : ?>
			<div class="kk-data-desk-stat<?php echo $hiddenBuyback ? ' kk-data-desk-stat--hidden' : ''; ?>"<?php echo $editable ? ' data-kk-stat-block="buyback"' : ''; ?>>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the toggle is built from escaped values + the theme's own icon SVG.
				echo $statToggle('buyback', $hiddenBuyback);
				?>
				<p class="kk-data-desk-stat-label"<?php echo $editable ? ' data-kk-stat-field="buyback-label"' : ''; ?>><?php echo esc_html($buybackLabel); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo isset($data['buyback']) && $data['buyback'] !== null ? esc_html(Markets::formatPrice((float) $data['buyback'], $currency)) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note"<?php echo $editable ? ' data-kk-stat-field="buyback-sub"' : ''; ?>>
					<?php if ($buybackSub !== null) : ?>
						<?php echo esc_html($buybackSub); ?>
					<?php elseif (isset($data['spread']) && $data['spread'] !== null) : ?>
						<?php
						/* translators: %s: sell-buyback spread in percent. */
						printf(esc_html__('Spread jual–buyback %s', 'koperasikita'), esc_html(number_format_i18n((float) $data['spread'], 1) . '%'));
						?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>
			<?php if ($editable || !$hiddenPerformance) : ?>
			<div class="kk-data-desk-stat<?php echo $hiddenPerformance ? ' kk-data-desk-stat--hidden' : ''; ?>"<?php echo $editable ? ' data-kk-stat-block="performance"' : ''; ?>>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the toggle is built from escaped values + the theme's own icon SVG.
				echo $statToggle('performance', $hiddenPerformance);
				?>
				<p class="kk-data-desk-stat-label"<?php echo $editable ? ' data-kk-stat-field="performance-label"' : ''; ?>><?php echo esc_html($performanceLabel); ?></p>
				<p class="kk-data-desk-stat-value">
					<?php echo isset($data['week_change']) && $data['week_change'] !== null ? esc_html(Markets::formatChange((float) $data['week_change'])) : '&mdash;'; ?>
				</p>
				<p class="kk-data-desk-stat-note"<?php echo $editable ? ' data-kk-stat-field="performance-sub"' : ''; ?>>
					<?php if ($performanceSub !== null) : ?>
						<?php echo esc_html($performanceSub); ?>
					<?php elseif (isset($data['streak']) && (int) $data['streak'] > 0) : ?>
						<?php
						/* translators: %d: consecutive up days. (Plain __() — the theme's po2mo has no plural support.) */
						printf(esc_html__('%d hari beruntun di zona hijau', 'koperasikita'), (int) $data['streak']);
						?>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>

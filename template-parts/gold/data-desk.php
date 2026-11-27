<?php
/**
 * Gold Data Desk: the gold brands as a data table - one row per
 * priced brand with the price per gram and the last-updated date.
 *
 * Expects $args['brands'] as array<string, \KoperasiKita\Providers\MarketPrice>
 * keyed by brand slug, and $args['client'] as the gold client info
 * (provider + client API URL) for live refresh. Brands without a
 * saved snapshot price are never passed in here - see
 * Gold::brandPrices(). When the array is empty this template renders
 * an honest empty state and no table at all.
 *
 * The rows carry the same data-kk-symbol / data-kk-price hooks as
 * the Markets strip, so the gold-markets script refreshes the
 * prices live without a page reload. The three column headers can
 * be overridden per template via $args['headers'] (key => label,
 * empty = the default label); in the template editor's preview
 * ($args['editable']) they are inline-editable like the panel's
 * stat labels.
 *
 * Table style ($args['table_style'], per template): flat is the
 * table as shipped; striped bands alternate rows; cards renders
 * each row as a separated card; chart draws the brands' price
 * history as a multiline chart (one line per brand, with a
 * legend) above the table. The title ($args['title']) renders
 * above the table by default, or inline with it as a caption bar
 * when $args['title_display'] is 'inline'. Type size and cell
 * padding come from $args['font_size'] / $args['cell_padding']
 * (defaults 14px / 8px, the shipped values). $args['header_bg'] /
 * $args['header_text'] override the thead band colours; empty
 * means the palette band. Chart data arrives in $args['chart']
 * (DataDesk::tableChartData() shape); without it the template
 * reads the real history itself.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Markets;

$brands = isset($args['brands']) && is_array($args['brands']) ? $args['brands'] : [];
$client = isset($args['client']) && is_array($args['client']) ? $args['client'] : [];
$palette = DataDesk::sanitizePalette(isset($args['palette']) ? (string) $args['palette'] : DataDesk::DEFAULT_PALETTE);
// Preview renders (templates page, widget forms) pass 'preview' =>
// true: the rows are fixed sample prices, never live snapshots, the
// badge says so, and the live-refresh hooks are left off so no
// script ever overwrites the sample rows with real prices.
$preview = !empty($args['preview']);
// The template editor's preview (editable) renders the column
// headers with their edit hooks, like the panel's stat labels;
// every other render (frontend, widget previews) does not.
$editable = $preview && !empty($args['editable']);
// Per-template header labels: a stored override wins, empty
// means the default label below.
$headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
$headerLabel = static function (string $key, string $default) use ($headers): string {
	$override = isset($headers[$key]) ? sanitize_text_field((string) $headers[$key]) : '';
	return $override !== '' ? $override : $default;
};
$brandHeader = $headerLabel('brand', __('Brand', 'koperasikita'));
$priceHeader = $headerLabel('price', __('Price per gram', 'koperasikita'));
$updatedHeader = $headerLabel('updated', __('Updated', 'koperasikita'));

// Table display settings, all per template. The defaults are the
// shipped table, so a template without these settings renders
// exactly as before: no extra class, no inline style, no title.
$tableStyle = DataDesk::sanitizeTableStyle($args['table_style'] ?? DataDesk::DEFAULT_TABLE_STYLE);
$titleDisplay = DataDesk::sanitizeTitleDisplay($args['title_display'] ?? DataDesk::DEFAULT_TITLE_DISPLAY);
$fontSize = DataDesk::sanitizeFontSize($args['font_size'] ?? DataDesk::DEFAULT_FONT_SIZE);
$cellPadding = DataDesk::sanitizeCellPadding($args['cell_padding'] ?? DataDesk::DEFAULT_CELL_PADDING);
$chartAnimate = !isset($args['chart_animate']) || (bool) $args['chart_animate'];
$headerBg = DataDesk::sanitizeHexColor($args['header_bg'] ?? '');
$headerText = DataDesk::sanitizeHexColor($args['header_text'] ?? '');
$days = isset($args['days']) ? max(3, min(30, (int) $args['days'])) : DataDesk::DEFAULT_DAYS;

// The title may use the panel's {label} / {day} tokens: {label}
// is the first brand shown, {day} the chart window in days.
$rawTitle = isset($args['title']) ? (string) $args['title'] : '';
$title = '';
if (trim($rawTitle) !== '' && $brands !== []) {
	$firstBrand = reset($brands);
	$firstLabel = $firstBrand instanceof \KoperasiKita\Providers\MarketPrice ? (string) $firstBrand->label : '';
	$title = DataDesk::replaceTitleTokens($rawTitle, $firstLabel, $days);
}

// Latest update across the rows, for the inline caption bar.
$latestUpdated = 0;
foreach ($brands as $price) {
	if ($price instanceof \KoperasiKita\Providers\MarketPrice && $price->updated > $latestUpdated) {
		$latestUpdated = (int) $price->updated;
	}
}

// Chart series for the chart style. The preview passes sample
// series; a live render without one reads the real history.
$chart = null;
if ($tableStyle === 'chart') {
	$chart = isset($args['chart']) && is_array($args['chart']) ? $args['chart'] : null;
	if ($chart === null) {
		$chart = DataDesk::tableChartData([
			'brands' => array_keys($brands),
			'days' => $days,
			'chart_colors' => $args['chart_colors'] ?? [],
		]);
	}
}

// Only non-default values reach the markup: a template without
// these settings keeps the shipped classes and no inline style.
$wrapperVars = [];
if ($fontSize !== DataDesk::DEFAULT_FONT_SIZE) {
	$wrapperVars[] = '--dd-table-font-size:' . $fontSize . 'px';
}
if ($cellPadding !== DataDesk::DEFAULT_CELL_PADDING) {
	$wrapperVars[] = '--dd-table-cell-padding:' . $cellPadding . 'px';
}
if ($headerBg !== '') {
	$wrapperVars[] = '--dd-thead-bg:' . $headerBg;
}
if ($headerText !== '') {
	$wrapperVars[] = '--dd-thead-text:' . $headerText;
}
$wrapperClasses = ['kk-gold-data-desk', 'kk-gold-data-desk--' . $palette];
if ($headerBg !== '' || $headerText !== '') {
	$wrapperClasses[] = 'has-custom-head';
}
$tableClasses = ['kk-data-desk-table'];
if ($tableStyle !== DataDesk::DEFAULT_TABLE_STYLE) {
	$tableClasses[] = 'kk-data-desk-table--' . $tableStyle;
}

if ($brands === []) {
	?>
	<p class="kk-widget-empty"><?php esc_html_e('Gold prices are not available yet.', 'koperasikita'); ?></p>
	<?php
	return;
}
?>

<?php if ($title !== '' && $titleDisplay === 'above') : ?>
	<h3 class="kk-data-desk-table-title"><?php echo esc_html($title); ?></h3>
<?php endif; ?>
<div
	class="<?php echo esc_attr(implode(' ', $wrapperClasses)); ?>"
	<?php if ($wrapperVars !== []) : ?>
		style="<?php echo esc_attr(implode(';', $wrapperVars)); ?>"
	<?php endif; ?>
	<?php if (!$preview) : ?>
		data-kk-market-prices
		data-kk-market="gold"
		data-kk-provider="<?php echo esc_attr((string) ($client['provider'] ?? 'manual')); ?>"
		<?php if (!empty($client['clientApiUrl'])) : ?>
			data-kk-client-api="<?php echo esc_attr((string) $client['clientApiUrl']); ?>"
		<?php endif; ?>
	<?php endif; ?>
>
	<?php if ($preview) : ?>
		<p class="kk-data-desk-preview-badge"><?php esc_html_e('Preview — sample data', 'koperasikita'); ?></p>
	<?php endif; ?>
	<?php if ($title !== '' && $titleDisplay === 'inline') : ?>
		<div class="kk-data-desk-table-caption">
			<span class="kk-data-desk-table-caption-title"><?php echo esc_html($title); ?></span>
			<?php if ($latestUpdated > 0) : ?>
				<span class="kk-data-desk-table-caption-meta"><?php
					/* translators: %s: last-updated date. */
					printf(esc_html__('Updated %s', 'koperasikita'), esc_html(date_i18n(get_option('date_format'), $latestUpdated)));
				?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ($tableStyle === 'chart') : ?>
		<?php
		// Multiline chart: one line per brand with history, shared
		// axes across every series (the union of dates), axis labels
		// in thousands of rupiah like the panel chart. A brand with
		// fewer than two points is not a line; with no plotted brand
		// at all the chart is the honest empty state, never a flat
		// invented line. The legend names every plotted brand with
		// its line colour. Animation is a CSS draw-on (pathLength
		// normalises the dash), off when the template disables it,
		// and always off under prefers-reduced-motion.
		$series = is_array($chart['series'] ?? null) ? $chart['series'] : [];
		$chartW = 640;
		$chartH = 300;
		$padL = 56;
		$padR = 16;
		$padT = 24;
		$padB = 32;
		$plotW = $chartW - $padL - $padR;
		$plotH = $chartH - $padT - $padB;
		$allPrices = [];
		$allDates = [];
		foreach ($series as $line) {
			foreach ($line['points'] as $point) {
				$allPrices[] = (float) $point['price'];
				$allDates[] = (string) $point['date'];
			}
		}
		$allDates = array_values(array_unique($allDates));
		sort($allDates);
		$minLabel = '';
		$maxLabel = '';
		$midLabel = '';
		$dateLabels = [];
		$min = 0.0;
		$max = 1.0;
		if ($series !== [] && $allPrices !== [] && $allDates !== []) {
			$min = min($allPrices);
			$max = max($allPrices);
			$span = $max - $min;
			$pad = $span > 0 ? $span * 0.15 : $max * 0.05;
			$min -= $pad;
			$max += $pad;
			$minLabel = number_format_i18n($min / 1000, 0);
			$maxLabel = number_format_i18n($max / 1000, 0);
			$midLabel = number_format_i18n((($min + $max) / 2) / 1000, 0);
			// Up to five date labels, evenly spaced over the window.
			$dateCount = count($allDates);
			$labelEvery = max(1, (int) floor($dateCount / 4));
			foreach ($allDates as $i => $date) {
				if ($i % $labelEvery === 0 || $i === $dateCount - 1) {
					$dateLabels[$date] = date_i18n('D d', strtotime($date));
				}
			}
		}
		$xFor = static function (string $date) use ($allDates, $padL, $plotW): float {
			$count = count($allDates);
			if ($count <= 1) {
				return $padL + $plotW / 2;
			}
			$index = array_search($date, $allDates, true);
			$index = $index === false ? 0 : $index;
			return $padL + ($index / ($count - 1)) * $plotW;
		};
		$yFor = static function (float $price) use ($min, $max, $padT, $plotH): float {
			return $padT + (1 - ($price - $min) / max(1.0, $max - $min)) * $plotH;
		};
		?>
		<div class="kk-data-desk-multi-chart<?php echo $chartAnimate ? ' kk-multi-chart--animate' : ''; ?>">
			<?php if ($series !== []) : ?>
				<svg viewBox="0 0 <?php echo (int) $chartW; ?> <?php echo (int) $chartH; ?>" width="100%" role="img" aria-label="<?php echo esc_attr($title !== '' ? $title : __('Gold price history', 'koperasikita')); ?>">
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) $padT; ?>" x2="<?php echo (int) $padL; ?>" y2="<?php echo (int) ($chartH - $padB); ?>" class="kk-data-desk-axis" />
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) ($chartH - $padB); ?>" x2="<?php echo (int) ($chartW - $padR); ?>" y2="<?php echo (int) ($chartH - $padB); ?>" class="kk-data-desk-axis" />
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) ($padT + $plotH / 2); ?>" x2="<?php echo (int) ($chartW - $padR); ?>" y2="<?php echo (int) ($padT + $plotH / 2); ?>" class="kk-data-desk-axis kk-data-desk-axis--grid" />
					<text x="<?php echo (int) ($padL - 8); ?>" y="<?php echo (int) ($padT + 4); ?>" text-anchor="end" class="kk-data-desk-axis-label"><?php echo esc_html($maxLabel); ?></text>
					<text x="<?php echo (int) ($padL - 8); ?>" y="<?php echo (int) ($padT + $plotH / 2 + 4); ?>" text-anchor="end" class="kk-data-desk-axis-label"><?php echo esc_html($midLabel); ?></text>
					<text x="<?php echo (int) ($padL - 8); ?>" y="<?php echo (int) ($chartH - $padB); ?>" text-anchor="end" class="kk-data-desk-axis-label"><?php echo esc_html($minLabel); ?></text>
					<?php foreach ($series as $lineIndex => $line) : ?>
						<?php
						$points = implode(' ', array_map(static function (array $point) use ($xFor, $yFor): string {
							return number_format($xFor((string) $point['date']), 1, '.', '') . ',' . number_format($yFor((float) $point['price']), 1, '.', '');
						}, $line['points']));
						$last = $line['points'][count($line['points']) - 1];
						?>
						<polyline
							fill="none"
							points="<?php echo esc_attr($points); ?>"
							class="kk-multi-line"
							pathLength="1"
							style="stroke: <?php echo esc_attr((string) $line['color']); ?>;<?php echo $chartAnimate ? ' animation-delay: ' . number_format($lineIndex * 0.12, 2, '.', '') . 's;' : ''; ?>"
						/>
						<circle
							cx="<?php echo esc_attr(number_format($xFor((string) $last['date']), 1, '.', '')); ?>"
							cy="<?php echo esc_attr(number_format($yFor((float) $last['price']), 1, '.', '')); ?>"
							r="4"
							class="kk-multi-dot"
							style="fill: <?php echo esc_attr((string) $line['color']); ?>;"
						/>
					<?php endforeach; ?>
					<?php foreach ($dateLabels as $date => $label) : ?>
						<text x="<?php echo esc_attr(number_format($xFor($date), 1, '.', '')); ?>" y="<?php echo (int) ($chartH - 8); ?>" text-anchor="middle" class="kk-data-desk-axis-label"><?php echo esc_html($label); ?></text>
					<?php endforeach; ?>
				</svg>
				<ul class="kk-multi-legend">
					<?php foreach ($series as $line) : ?>
						<li>
							<span class="kk-multi-swatch" style="background: <?php echo esc_attr((string) $line['color']); ?>;"></span>
							<?php echo esc_html((string) $line['label']); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<svg viewBox="0 0 <?php echo (int) $chartW; ?> <?php echo (int) $chartH; ?>" width="100%" role="img" aria-label="<?php esc_attr_e('Price history not available yet', 'koperasikita'); ?>">
					<line x1="<?php echo (int) $padL; ?>" y1="<?php echo (int) ($chartH / 2); ?>" x2="<?php echo (int) ($chartW - $padR); ?>" y2="<?php echo (int) ($chartH / 2); ?>" class="kk-data-desk-line kk-data-desk-line--placeholder" />
				</svg>
				<p class="kk-data-desk-placeholder"><?php esc_html_e('No price history yet.', 'koperasikita'); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<table class="<?php echo esc_attr(implode(' ', $tableClasses)); ?>">
		<thead>
			<tr>
				<th scope="col"<?php echo $editable ? ' data-kk-header-field="brand"' : ''; ?>><?php echo esc_html($brandHeader); ?></th>
				<th scope="col"<?php echo $editable ? ' data-kk-header-field="price"' : ''; ?>><?php echo esc_html($priceHeader); ?></th>
				<th scope="col"<?php echo $editable ? ' data-kk-header-field="updated"' : ''; ?>><?php echo esc_html($updatedHeader); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($brands as $slug => $price) : ?>
				<tr data-kk-symbol="<?php echo esc_attr((string) $slug); ?>">
					<th scope="row"><?php echo esc_html($price->label); ?></th>
					<td data-kk-price><?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?></td>
					<td>
						<?php if ($price->updated > 0) : ?>
							<?php echo esc_html(date_i18n(get_option('date_format'), $price->updated)); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

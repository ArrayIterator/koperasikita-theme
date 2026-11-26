<?php
/**
 * Gold Data Desk: the gold brands as a data table — one row per
 * priced brand with the price per gram and the last-updated date.
 *
 * Expects $args['brands'] as array<string, \KoperasiKita\Providers\MarketPrice>
 * keyed by brand slug, and $args['client'] as the gold client info
 * (provider + client API URL) for live refresh. Brands without a
 * saved snapshot price are never passed in here — see
 * Gold::brandPrices(). When the array is empty this template renders
 * an honest empty state and no table at all.
 *
 * The rows carry the same data-kk-symbol / data-kk-price hooks as
 * the Markets strip, so the gold-markets script refreshes the
 * prices live without a page reload.
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

if ($brands === []) {
	?>
	<p class="kk-widget-empty"><?php esc_html_e('Gold prices are not available yet.', 'koperasikita'); ?></p>
	<?php
	return;
}
?>

<div
	class="kk-gold-data-desk kk-gold-data-desk--<?php echo esc_attr($palette); ?>"
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
	<table class="kk-data-desk-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e('Brand', 'koperasikita'); ?></th>
				<th scope="col"><?php esc_html_e('Price per gram', 'koperasikita'); ?></th>
				<th scope="col"><?php esc_html_e('Updated', 'koperasikita'); ?></th>
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

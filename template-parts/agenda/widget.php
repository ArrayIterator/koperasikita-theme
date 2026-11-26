<?php
/**
 * Agenda widget card: a dark panel with a header (people icon + the
 * widget title) and one row per event — a date badge on the left, the
 * title and a pin + location on the right. Every row carries a ribbon
 * naming its state: Akan Datang (upcoming), Berlangsung (happening
 * now) or Selesai (finished).
 *
 * Expects $args['title'] (string) and $args['items'] as the
 * AgendaWidget::items() array.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Icons\Icon;

$title = isset($args['title']) ? (string) $args['title'] : '';
$items = isset($args['items']) && is_array($args['items']) ? $args['items'] : [];

if ($items === []) {
	return;
}

$ribbonLabels = [
	'upcoming' => __('Akan Datang', 'koperasikita'),
	'live' => __('Berlangsung', 'koperasikita'),
	'done' => __('Selesai', 'koperasikita'),
];
?>

<section class="kk-agenda-widget-card">
	<?php if ($title !== '') : ?>
		<header class="kk-agenda-widget-head">
			<span class="kk-agenda-widget-icon"><?php echo Icon::svg('users', 18); ?></span>
			<h2 class="kk-agenda-widget-title"><?php echo esc_html($title); ?></h2>
		</header>
	<?php endif; ?>
	<ul class="kk-agenda-widget-list">
		<?php foreach ($items as $item) : ?>
			<?php $status = in_array($item['status'], ['upcoming', 'live', 'done'], true) ? $item['status'] : 'upcoming'; ?>
			<li class="kk-agenda-widget-item kk-agenda-widget-item--<?php echo esc_attr($status); ?>">
				<a class="kk-agenda-widget-link" href="<?php echo esc_url($item['url']); ?>">
					<span class="kk-agenda-widget-date">
						<span class="kk-agenda-widget-day"><?php echo esc_html($item['day']); ?></span>
						<span class="kk-agenda-widget-month"><?php echo esc_html($item['month']); ?></span>
					</span>
					<span class="kk-agenda-widget-info">
						<span class="kk-agenda-widget-item-title"><?php echo esc_html($item['title']); ?></span>
						<?php if ($item['location'] !== '') : ?>
							<span class="kk-agenda-widget-location">
								<?php echo Icon::svg('location', 14); ?>
								<?php echo esc_html($item['location']); ?>
							</span>
						<?php endif; ?>
					</span>
				</a>
				<span class="kk-agenda-widget-ribbon kk-agenda-widget-ribbon--<?php echo esc_attr($status); ?>">
					<?php echo esc_html($ribbonLabels[$status]); ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
</section>

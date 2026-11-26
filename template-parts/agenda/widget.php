<?php
/**
 * Agenda widget card: a dark panel with a header (people icon + the
 * widget title) and one row per event — a date badge on the left, the
 * title and a pin + location on the right. The panel is dark in both
 * colour schemes, like the reference design.
 *
 * Status is kept subtle so the clean reference look survives: a live
 * event gets a green dot on its date badge, a finished event is
 * dimmed, upcoming events are plain. Screen readers get the status
 * as text; sighted users get the dot / dimming only.
 *
 * Expects $args['title'] (string) and $args['items'] as the
 * AgendaWidget::items() array. An empty list renders the same dark
 * card with an honest "no agenda" line — never fake events.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Icons\Icon;

$title = isset($args['title']) ? (string) $args['title'] : '';
$items = isset($args['items']) && is_array($args['items']) ? $args['items'] : [];

if ($title === '') {
	$title = __('Agenda Koperasi', 'koperasikita');
}

$statusLabels = [
	'live' => __('Berlangsung', 'koperasikita'),
	'done' => __('Selesai', 'koperasikita'),
];
?>

<section class="kk-agenda-widget-card">
	<header class="kk-agenda-widget-head">
		<span class="kk-agenda-widget-icon"><?php echo Icon::svg('users', 18); ?></span>
		<h2 class="kk-agenda-widget-title"><?php echo esc_html($title); ?></h2>
	</header>
	<?php if ($items === []) : ?>
		<p class="kk-agenda-widget-empty"><?php esc_html_e('No agenda items yet.', 'koperasikita'); ?></p>
	<?php else : ?>
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
							<span class="kk-agenda-widget-item-title"><?php if (isset($statusLabels[$status])) : ?><span class="kk-agenda-widget-sr-only"><?php echo esc_html($statusLabels[$status]); ?>: </span><?php endif; ?><?php echo esc_html($item['title']); ?></span>
							<?php if ($item['location'] !== '') : ?>
								<span class="kk-agenda-widget-location">
									<?php echo Icon::svg('location', 14); ?>
									<?php echo esc_html($item['location']); ?>
								</span>
							<?php endif; ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

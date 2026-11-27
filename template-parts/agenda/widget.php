<?php
/**
 * Agenda widget card: a panel with a header (icon + the widget
 * title) and one row per event - a date badge on the left, the
 * title and a pin + location on the right. The panel follows the
 * site colour scheme (light card in light mode, the dark-green
 * reference card in dark mode); the Card style setting can force
 * either variant, or Choose Own for custom Background + Accent text
 * colours. The header icon is shown only when "Show icon"
 * is on, and the title links to the "Link to" post or page when
 * one is set.
 *
 * Status is kept subtle so the clean reference look survives: a live
 * event gets a green dot on its date badge, a finished event is
 * dimmed, upcoming events are plain. Screen readers get the status
 * as text; sighted users get the dot / dimming only.
 *
 * Expects $args['title'] (string) and $args['items'] as the
 * AgendaWidget::items() array, plus $args['show_icon'] (bool),
 * $args['icon'] (string), $args['style'] ('auto'|'dark'|'light'|
 * 'custom'), $args['bg_color'] and $args['accent_color'] (hex colour
 * or '' - Choose Own only) and $args['link_url'] (string, '' when no
 * link). An empty list renders the same card with an honest "no
 * agenda" line - never fake events. Choose Own sets the card's
 * --kk-agenda-bg / --kk-agenda-green as inline vars, one per colour
 * that is set; an unset colour falls back to the theme value.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Icons\Icon;

$title = isset($args['title']) ? (string) $args['title'] : '';
$items = isset($args['items']) && is_array($args['items']) ? $args['items'] : [];
$showIcon = !isset($args['show_icon']) || (bool) $args['show_icon'];
$icon = isset($args['icon']) && is_string($args['icon']) && Icon::exists($args['icon']) ? $args['icon'] : 'users';
$style = isset($args['style']) && in_array($args['style'], ['auto', 'dark', 'light', 'custom'], true) ? $args['style'] : 'auto';
$bgColor = isset($args['bg_color']) ? sanitize_hex_color((string) $args['bg_color']) : '';
$bgColor = is_string($bgColor) ? $bgColor : '';
$accentColor = isset($args['accent_color']) ? sanitize_hex_color((string) $args['accent_color']) : '';
$accentColor = is_string($accentColor) ? $accentColor : '';
$linkUrl = isset($args['link_url']) && is_string($args['link_url']) ? $args['link_url'] : '';

if ($title === '') {
	$title = __('Agenda Koperasi', 'koperasikita');
}

$statusLabels = [
	'live' => __('Berlangsung', 'koperasikita'),
	'done' => __('Selesai', 'koperasikita'),
];

$cardClass = 'kk-agenda-widget-card';
$cardStyle = '';

if ($style === 'dark') {
	$cardClass .= ' kk-agenda-widget-card--dark';
} elseif ($style === 'light') {
	$cardClass .= ' kk-agenda-widget-card--light';
} elseif ($style === 'custom') {
	// Inline vars beat the class-level ones, so a Choose Own card
	// keeps its colours in either site scheme; a colour left empty
	// is simply not set, so that slot falls back to the theme.
	$customVars = [];

	if ('' !== $bgColor) {
		$customVars[] = '--kk-agenda-bg: ' . $bgColor;
	}

	if ('' !== $accentColor) {
		$customVars[] = '--kk-agenda-green: ' . $accentColor;
	}

	if ($customVars !== []) {
		$cardStyle = implode('; ', $customVars) . ';';
	}
}
?>

<section class="<?php echo esc_attr($cardClass); ?>"<?php echo '' !== $cardStyle ? ' style="' . esc_attr($cardStyle) . '"' : ''; ?>>
	<header class="kk-agenda-widget-head">
		<?php if ($showIcon) : ?>
			<span class="kk-agenda-widget-icon"><?php echo Icon::svg($icon, 18); ?></span>
		<?php endif; ?>
		<h2 class="kk-agenda-widget-title"><?php if ('' !== $linkUrl) : ?><a class="kk-agenda-widget-title-link" href="<?php echo esc_url($linkUrl); ?>"><?php echo esc_html($title); ?></a><?php else : ?><?php echo esc_html($title); ?><?php endif; ?></h2>
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

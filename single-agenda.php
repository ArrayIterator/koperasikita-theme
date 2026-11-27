<?php
/**
 * The single agenda item template: event details, description, back link.
 *
 * Pending items never reach this template for visitors: WordPress serves
 * a 404 for them unless the viewer can edit the item.
 *
 * The event Location is its own element (the "location picker"): it can
 * be enabled/disabled and placed in five positions (Customizer -> Agenda
 * -> Event Location). It is NOT a row of the agenda-meta table.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-single-layout">
	<div class="kk-content-area">
		<?php
		while (have_posts()) :
			the_post();
			get_template_part('template-parts/content/breadcrumb');
			$font_size = \KoperasiKita\Features\ArticleFontSize::currentSize();
			$kk_location_position = \KoperasiKita\Features\Agenda::locationPosition();
			$kk_location_enabled = \KoperasiKita\Features\Agenda::locationEnabled();
			?>

			<article class="kk-article kk-agenda-single" style="--article-font-size: <?php echo esc_attr((string) $font_size); ?>px">
				<header class="kk-article-header">
					<?php
					$kk_agenda_start = \KoperasiKita\Features\Agenda::getMeta(get_the_ID())['start'];
					$kk_agenda_ts = \KoperasiKita\Features\Agenda::eventTimestamp($kk_agenda_start);
					if ($kk_agenda_ts > 0) :
						?>
						<p class="kk-agenda-date-badge" aria-hidden="true">
							<span class="kk-agenda-date-day"><?php echo esc_html(wp_date('j', $kk_agenda_ts)); ?></span>
							<span class="kk-agenda-date-month"><?php echo esc_html(wp_date('M', $kk_agenda_ts)); ?></span>
						</p>
					<?php endif; ?>
					<h1 class="kk-article-title"><?php the_title(); ?></h1>
					<?php
					if ($kk_location_enabled && $kk_location_position === 'above-info') {
						get_template_part('template-parts/agenda/agenda-location');
					}
					?>
					<?php get_template_part('template-parts/agenda/agenda-meta'); ?>
					<?php
					if ($kk_location_enabled && $kk_location_position === 'below-info') {
						get_template_part('template-parts/agenda/agenda-location');
					}
					?>
				</header>

				<?php if (has_post_thumbnail()) : ?>
					<figure class="kk-article-media">
						<?php the_post_thumbnail('large'); ?>
					</figure>
				<?php endif; ?>

				<?php
				if ($kk_location_enabled && $kk_location_position === 'below-thumbnail') {
					get_template_part('template-parts/agenda/agenda-location');
				}
				?>

				<?php
				if ($kk_location_enabled && $kk_location_position === 'before-article') {
					get_template_part('template-parts/agenda/agenda-location');
				}
				?>

				<div class="kk-article-content">
					<?php the_content(); ?>
				</div>

				<?php
				if ($kk_location_enabled && $kk_location_position === 'below-article') {
					get_template_part('template-parts/agenda/agenda-location');
				}
				?>
			</article>

			<p class="kk-agenda-back">
				<a href="<?php echo esc_url(get_post_type_archive_link('agenda')); ?>">
					<?php esc_html_e('Back to the agenda', 'koperasikita'); ?>
				</a>
			</p>

		<?php endwhile; ?>
	</div>

	<?php get_sidebar('agenda'); ?>
</div>

<?php
get_footer();

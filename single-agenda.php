<?php
/**
 * The single agenda item template: event details, description, back link.
 *
 * Pending items never reach this template for visitors: WordPress serves
 * a 404 for them unless the viewer can edit the item.
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
			?>

			<article class="kk-article kk-agenda-single" style="--article-font-size: <?php echo esc_attr((string) $font_size); ?>px">
				<header class="kk-article-header">
					<h1 class="kk-article-title"><?php the_title(); ?></h1>
					<?php get_template_part('template-parts/agenda/agenda-meta'); ?>
				</header>

				<?php if (has_post_thumbnail()) : ?>
					<figure class="kk-article-media">
						<?php the_post_thumbnail('large'); ?>
					</figure>
				<?php endif; ?>

				<div class="kk-article-content">
					<?php the_content(); ?>
				</div>
			</article>

			<p class="kk-agenda-back">
				<a href="<?php echo esc_url(get_post_type_archive_link('agenda')); ?>">
					<?php esc_html_e('Back to the agenda', 'koperasikita'); ?>
				</a>
			</p>

		<?php endwhile; ?>
	</div>

	<?php get_sidebar('single'); ?>
</div>

<?php
get_footer();

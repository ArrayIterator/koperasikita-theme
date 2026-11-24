<?php
/**
 * The front page template.
 *
 * Hero grid, editor picks, latest news and category blocks.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-home">
	<?php get_template_part( 'template-parts/content/breadcrumb' ); ?>

	<?php get_template_part( 'template-parts/home/hero-grid' ); ?>

	<div class="kk-home-layout">
		<div class="kk-home-primary">
			<?php get_template_part( 'template-parts/home/latest-news' ); ?>

			<?php
			// Category blocks: the three Customizer slots, in order.
			foreach ( \KoperasiKita\Features\Homepage::categoryBlocks() as $category ) {
				set_query_var( 'kk_category_block', $category );
				get_template_part( 'template-parts/home/category-block' );
			}
			?>
		</div>

		<div class="kk-home-secondary">
			<?php get_template_part( 'template-parts/home/featured-sidebar' ); ?>
			<?php get_sidebar( 'news' ); ?>
		</div>
	</div>
</div>

<?php
get_footer();

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
	<?php get_template_part( 'template-parts/home/hero-grid' ); ?>

	<div class="kk-home-layout">
		<div class="kk-home-primary">
			<?php get_template_part( 'template-parts/home/latest-news' ); ?>

			<?php
			// Category blocks: up to 3 categories chosen by post count.
			$categories = get_categories(
				array(
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 3,
					'hide_empty' => true,
				)
			);

			foreach ( $categories as $category ) {
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

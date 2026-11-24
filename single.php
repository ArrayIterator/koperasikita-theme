<?php
/**
 * The single post template.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-single-layout">
	<div class="kk-content-area">
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content/breadcrumb' );
			get_template_part( 'template-parts/content/single-article' );

			// Related posts: same category, 3 posts.
			$related = new WP_Query(
				array(
					'post_type'           => 'post',
					'posts_per_page'      => 3,
					'post__not_in'        => array( get_the_ID() ),
					'category__in'        => wp_get_post_categories( get_the_ID() ),
					'ignore_sticky_posts' => true,
				)
			);

			if ( $related->have_posts() ) :
				?>
				<section class="kk-related" aria-labelledby="kk-related-heading">
					<h2 class="kk-section-title" id="kk-related-heading"><?php esc_html_e( 'Related news', 'koperasikita' ); ?></h2>
					<div class="kk-card-grid kk-card-grid-3">
						<?php
						while ( $related->have_posts() ) :
							$related->the_post();
							get_template_part( 'template-parts/content/card-news' );
						endwhile;
						?>
					</div>
				</section>
				<?php
				wp_reset_postdata();
			endif;

			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
		endwhile;
		?>
	</div>

	<?php get_sidebar( 'single' ); ?>
</div>

<?php
get_footer();

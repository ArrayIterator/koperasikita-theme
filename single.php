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

			if ( get_theme_mod( 'kk_single_author_box', true ) ) {
				$author_id    = (int) get_the_author_meta( 'ID' );
				$author_bio   = get_the_author_meta( 'description' );
				$author_posts = count_user_posts( $author_id, 'post' );

				if ( $author_bio || $author_posts > 0 ) {
					?>
					<aside class="kk-author-box" aria-label="<?php esc_attr_e( 'About the author', 'koperasikita' ); ?>">
						<div class="kk-author-avatar"><?php echo get_avatar( $author_id, 64 ); ?></div>
						<div class="kk-author-info">
							<p class="kk-author-name"><?php echo esc_html( get_the_author() ); ?></p>
							<?php
							if ( $author_bio ) {
								?>
								<p class="kk-author-bio"><?php echo esc_html( $author_bio ); ?></p>
								<?php
							}
							?>
							<p class="kk-author-link">
								<a href="<?php echo esc_url( get_author_posts_url( $author_id ) ); ?>">
									<?php
									/* translators: %s: Author display name. */
									printf( esc_html__( 'More from %s', 'koperasikita' ), esc_html( get_the_author() ) );
									?>
								</a>
							</p>
						</div>
					</aside>
					<?php
				}
			}

			// Related posts: same category, kk_single_related_count posts.
			if ( get_theme_mod( 'kk_single_related', true ) ) {
				$related = new WP_Query(
					array(
						'post_type'           => 'post',
						'posts_per_page'      => min( 6, max( 1, (int) get_theme_mod( 'kk_single_related_count', 4 ) ) ),
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
			}

			if ( \KoperasiKita\Features\Comments::shouldShow( get_the_ID() ) ) {
				comments_template();
			}
		endwhile;
		?>
	</div>

	<?php get_sidebar( 'single' ); ?>
</div>

<?php
get_footer();

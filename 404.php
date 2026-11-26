<?php
/**
 * The 404 template.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-not-found">
	<section class="kk-404" aria-labelledby="kk-404-heading">
		<p class="kk-404-code" aria-hidden="true">404</p>
		<h1 class="kk-page-title" id="kk-404-heading"><?php esc_html_e( 'Page not found', 'koperasikita' ); ?></h1>
		<p class="kk-404-text">
			<?php esc_html_e( 'The page you are looking for may have moved, or it no longer exists. Try searching, or start from the latest news.', 'koperasikita' ); ?>
		</p>

		<?php get_search_form(); ?>

		<p class="kk-404-home">
			<a class="kk-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to homepage', 'koperasikita' ); ?></a>
		</p>

		<?php
		$latest = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 5,
			)
		);

		if ( $latest->have_posts() ) :
			?>
			<h2 class="kk-section-title"><?php esc_html_e( 'Latest news', 'koperasikita' ); ?></h2>
			<div class="kk-404-latest kk-card-grid kk-layout-list">
				<?php
				while ( $latest->have_posts() ) :
					$latest->the_post();
					get_template_part( 'template-parts/content/card-news' );
				endwhile;
				?>
			</div>
			<?php
			wp_reset_postdata();
		endif;
		?>
	</section>
</div>

<?php
get_footer();

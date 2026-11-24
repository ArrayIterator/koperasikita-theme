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
			<ul class="kk-plain-list">
				<?php
				while ( $latest->have_posts() ) :
					$latest->the_post();
					?>
					<li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
					<?php
				endwhile;
				?>
			</ul>
			<?php
			wp_reset_postdata();
		endif;
		?>
	</section>
</div>

<?php
get_footer();

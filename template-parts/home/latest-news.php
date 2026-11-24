<?php
/**
 * Latest news: list or grid per kk_homepage_layout, kk_latest_count posts.
 *
 * @package KoperasiKita
 */

$latest_count = (int) get_theme_mod( 'kk_latest_count', 10 );
$latest_count = max( 1, $latest_count );
$layout       = get_theme_mod( 'kk_homepage_layout', 'grid' );
$layout       = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

$latest = new WP_Query(
	array(
		'post_type'           => 'post',
		'posts_per_page'      => $latest_count,
		'ignore_sticky_posts' => true,
	)
);

if ( ! $latest->have_posts() ) {
	return;
}
?>

<section class="kk-latest" aria-labelledby="kk-latest-heading">
	<div class="kk-section-head">
		<h2 class="kk-section-title" id="kk-latest-heading"><?php esc_html_e( 'Latest News', 'koperasikita' ); ?></h2>
		<?php get_template_part( 'template-parts/content/layout-switcher' ); ?>
	</div>

	<div class="kk-latest-items kk-layout-<?php echo esc_attr( $layout ); ?>" id="kk-latest-items">
		<?php
		while ( $latest->have_posts() ) :
			$latest->the_post();
			get_template_part( 'template-parts/content/card-news' );
		endwhile;
		?>
	</div>
</section>

<?php
wp_reset_postdata();

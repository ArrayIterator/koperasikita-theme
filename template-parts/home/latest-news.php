<?php
/**
 * Latest news: list or grid per kk_homepage_layout, kk_latest_count posts.
 *
 * @package KoperasiKita
 */

$latest_count = (int) get_theme_mod( 'kk_latest_count', 10 );
$latest_count = max( 1, $latest_count );
/* The visitor's saved layout wins over the Customizer default:
 * the switcher stores the choice in a `kk_layout` cookie (and in
 * localStorage), and the server reads the cookie so the list renders
 * in the right layout on first paint — no flash, no JS dependency. */
$layout       = get_theme_mod( 'kk_homepage_layout', 'grid' );
$layout       = in_array( $layout, array( 'grid', 'list', 'newsletter' ), true ) ? $layout : 'grid';
if ( isset( $_COOKIE['kk_layout'] ) ) {
	$saved = sanitize_key( wp_unslash( $_COOKIE['kk_layout'] ) );
	if ( in_array( $saved, array( 'grid', 'list', 'newsletter' ), true ) ) {
		$layout = $saved;
	}
}

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

$latest_title = (string) get_theme_mod( 'kk_latest_title', __( 'Latest News', 'koperasikita' ) );
$latest_title = trim( $latest_title );
$latest_eyebrow = (string) get_theme_mod( 'kk_latest_eyebrow', '' );
$latest_eyebrow = trim( $latest_eyebrow );
?>

<section class="kk-latest" aria-labelledby="kk-latest-heading">
	<div class="kk-section-head">
		<div>
			<?php if ( '' !== $latest_eyebrow ) : ?>
				<p class="kk-eyebrow"><?php echo esc_html( $latest_eyebrow ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $latest_title ) : ?>
				<h2 class="kk-section-title" id="kk-latest-heading"><?php echo esc_html( $latest_title ); ?></h2>
			<?php endif; ?>
		</div>
		<?php get_template_part( 'template-parts/content/layout-switcher' ); ?>
	</div>

	<?php if ( 'newsletter' === $layout ) : ?>
		<?php get_template_part( 'template-parts/home/newsletter', null, array( 'query' => $latest ) ); ?>
	<?php else : ?>
	<div class="kk-latest-items kk-layout-<?php echo esc_attr( $layout ); ?>" id="kk-latest-items">
		<?php
		while ( $latest->have_posts() ) :
			$latest->the_post();
			get_template_part( 'template-parts/content/card-news' );
		endwhile;
		?>
		<?php get_template_part( 'template-parts/home/newsletter-subscribe', null, array( 'hidden' => true ) ); ?>
	</div>
	<?php endif; ?>
</section>

<?php
wp_reset_postdata();

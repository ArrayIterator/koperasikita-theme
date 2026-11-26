<?php
/**
 * Latest news: list or grid per kk_homepage_layout, kk_latest_count posts.
 *
 * @package KoperasiKita
 */

$latest_count = (int) get_theme_mod( 'kk_latest_count', 10 );
$latest_count = max( 1, $latest_count );

/* The homepage is a static front page, so the current page lives
 * in the `page` query var (not `paged`, which is for the posts
 * page and archives). Fall back to `paged` so the same template
 * also paginates if it ever renders on a posts page. */
$paged = (int) get_query_var( 'page' );
if ( $paged < 1 ) {
	$paged = (int) get_query_var( 'paged' );
}
$paged = max( 1, $paged );
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
		'paged'               => $paged,
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

	<?php
	// Homepage pagination: the latest news is a custom query, so it
	// gets its own pagination here (the archive pagination only
	// covers the main query). Load-more falls back to numbered
	// pagination in the newsletter layout, which has no grid for the
	// loader to append cards to.
	$pagination_mode = \KoperasiKita\Features\Pagination::mode();
	if ( 'load-more' === $pagination_mode && 'newsletter' === $layout ) {
		$pagination_mode = 'pagination';
	}
	get_template_part( 'template-parts/content/pagination', null, array( 'query' => $latest, 'mode' => $pagination_mode ) );
	?>
</section>

<?php
wp_reset_postdata();

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

/* The newsletter lead is a front-page feature only: on paged pages
 * (/page/2/, ...) there is no lead story and no "More stories" —
 * just the plain list. The newsletter template always renders its
 * first card as a lead, so never use it when paged; force the plain
 * list layout instead. */
if ( $paged > 1 && 'newsletter' === $layout ) {
	$layout = 'list';
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
		<?php
		/* The newsletter-only rule and "More stories" label, rendered
		 * hidden like the subscribe block: the client-side switcher
		 * reveals all three when the visitor picks newsletter, and
		 * the flex order in newsletter.css places them after the lead
		 * whatever their DOM position is. Without them here, switching
		 * TO newsletter client-side would show no rule and no label. */
		if ( $latest->post_count > 1 ) :
			?>
			<hr class="kk-newsletter-rule mt-1 w-full border-0 border-t border-kk-gold" hidden>
			<p class="kk-newsletter-more mt-5 text-xs font-bold uppercase tracking-[0.14em] text-kk-green" hidden><?php esc_html_e( 'More stories', 'koperasikita' ); ?></p>
			<?php
		endif;
		?>
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

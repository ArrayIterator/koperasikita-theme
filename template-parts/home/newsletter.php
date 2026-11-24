<?php
/**
 * Newsletter layout for the latest-news section: lead story, subscribe
 * call to action, then a single-column feed of the remaining stories.
 *
 * Accepts $args['query'] (WP_Query). Without it, runs the same query as
 * latest-news.php (kk_latest_count posts, sticky posts ignored), so this
 * template is only a different presentation of the same posts.
 *
 * The markup reuses template-parts/content/card-news for every story, so
 * the lead is the first card and the feed rows are the remaining cards;
 * newsletter.css turns the first card into the lead and the rest into
 * single-column rows. The container keeps the kk-latest-items id and the
 * kk-layout-newsletter class the layout switcher expects.
 *
 * @package KoperasiKita
 */

if ( isset( $args['query'] ) && $args['query'] instanceof WP_Query ) {
	$newsletter_query = $args['query'];
} else {
	$newsletter_count = max( 1, (int) get_theme_mod( 'kk_latest_count', 10 ) );
	$newsletter_query = new WP_Query(
		array(
			'post_type'           => 'post',
			'posts_per_page'      => $newsletter_count,
			'ignore_sticky_posts' => true,
		)
	);
}

if ( ! $newsletter_query->have_posts() ) {
	return;
}
?>

<div class="kk-latest-items kk-layout-newsletter" id="kk-latest-items">
	<?php
	$newsletter_first = true;
	while ( $newsletter_query->have_posts() ) :
		$newsletter_query->the_post();
		get_template_part( 'template-parts/content/card-news' );
		if ( $newsletter_first ) :
			$newsletter_first = false;
			get_template_part( 'template-parts/home/newsletter-subscribe' );
			if ( $newsletter_query->post_count > 1 ) :
				?>
				<hr class="kk-newsletter-rule">
				<p class="kk-newsletter-more"><?php esc_html_e( 'More stories', 'koperasikita' ); ?></p>
				<?php
			endif;
		endif;
	endwhile;
	?>
</div>

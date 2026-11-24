<?php
/**
 * Hero grid: first post large, next four as a 2x2 grid of cards.
 *
 * Accepts $args['posts'] (array of WP_Post) or queries itself with kk_hero_count.
 *
 * @package KoperasiKita
 */

$hero_count = (int) get_theme_mod( 'kk_hero_count', 5 );
$hero_count = max( 1, $hero_count );

if ( isset( $args['posts'] ) && is_array( $args['posts'] ) ) {
	$hero_posts = $args['posts'];
} else {
	$hero_query = new WP_Query(
		array(
			'post_type'           => 'post',
			'posts_per_page'      => $hero_count,
			'ignore_sticky_posts' => false,
		)
	);
	$hero_posts = $hero_query->posts;
}

if ( empty( $hero_posts ) ) {
	return;
}

$lead  = array_shift( $hero_posts );
$rest  = $hero_posts;
?>

<section class="kk-hero" aria-label="<?php esc_attr_e( 'Top stories', 'koperasikita' ); ?>">
	<?php
	// Lead story.
	$post = $lead;
	setup_postdata( $post );
	?>
	<article class="kk-hero-lead">
		<a class="kk-hero-lead-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' ) ); ?>" alt="" loading="eager">
		</a>
		<div class="kk-hero-lead-body">
			<?php
			$categories = get_the_category();
			if ( ! empty( $categories ) ) {
				?>
				<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
				<?php
			}
			?>
			<h2 class="kk-hero-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
			<p class="kk-hero-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
			<p class="kk-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</p>
		</div>
	</article>

	<?php if ( ! empty( $rest ) ) : ?>
		<div class="kk-hero-grid">
			<?php
			foreach ( $rest as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news' );
			endforeach;
			?>
		</div>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</section>

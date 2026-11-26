<?php
/**
 * Hero: first post large, next four as a 2x2 grid of cards — or a
 * one-at-a-time slider when the Hero Presentation is Slider.
 *
 * Accepts $args['posts'] (array of WP_Post) or queries itself via
 * Homepage::heroPosts() (latest posts, or the picked categories when
 * the hero source is Category). The hero renders with NO heading,
 * exactly as before — the section headings belong to the sections
 * below it (Latest News, Highlights, Category Blocks).
 *
 * @package KoperasiKita
 */

if ( isset( $args['posts'] ) && is_array( $args['posts'] ) ) {
	$hero_posts = $args['posts'];
} else {
	$hero_posts = \KoperasiKita\Features\Homepage::heroPosts();
}

if ( empty( $hero_posts ) ) {
	return;
}

$hero_mode = (string) get_theme_mod( 'kk_hero_mode', 'grid' );
$hero_mode = in_array( $hero_mode, ['grid', 'slider'], true ) ? $hero_mode : 'grid';
$lead  = array_shift( $hero_posts );
$rest  = $hero_posts;
?>

<section class="kk-hero<?php echo 'slider' === $hero_mode ? ' kk-hero--slider' : ''; ?>" aria-label="<?php esc_attr_e( 'Top stories', 'koperasikita' ); ?>">
	<?php if ( 'slider' === $hero_mode ) : ?>
		<div class="kk-section-head kk-hero-head">
			<div class="kk-slider-controls">
				<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous story', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next story', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( 'slider' === $hero_mode ) : ?>
		<div class="kk-hero-slider" data-kk-slider>
			<?php
			// The lead is the first slide; the rest follow in order.
			array_unshift( $hero_posts, $lead );
			foreach ( $hero_posts as $post ) :
				setup_postdata( $post );
				?>
				<article class="kk-hero-slide">
					<a class="kk-hero-slide-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
						<img src="<?php echo esc_url( \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' ) ); ?>" alt="" loading="lazy">
					</a>
					<div class="kk-hero-slide-body">
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
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<?php
		// Lead story.
		$post = $lead;
		setup_postdata( $post );
		?>
		<article class="kk-hero-lead">
			<a class="kk-hero-lead-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
				<img src="<?php echo esc_url( \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' ) ); ?>" alt="" loading="eager" fetchpriority="high">
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
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</section>

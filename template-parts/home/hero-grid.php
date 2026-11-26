<?php
/**
 * Hero: lead story + cards (grid modes) or a one-at-a-time slider
 * (slider modes), optionally paired with the Hero Left / Hero Right
 * widget areas (Appearance -> Widgets).
 *
 * The Hero Presentation setting (kk_hero_mode) picks one of 12
 * modes: Grid Left / Right / Center, Grid + Widget, Leads +
 * Widgets, Slider One + Widget, Slider Full, Slider Center, and
 * Slider + Widget (1/3–2/3). The hero renders with NO heading,
 * exactly as before — the section headings belong to the sections
 * below it (Latest News, Highlights, Category Blocks).
 *
 * Accepts $args['posts'] (array of WP_Post) or queries itself via
 * Homepage::heroPosts() (latest posts, or the picked categories when
 * the hero source is Category).
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Hero;

if ( isset( $args['posts'] ) && is_array( $args['posts'] ) ) {
	$hero_posts = $args['posts'];
} else {
	$hero_posts = \KoperasiKita\Features\Homepage::heroPosts();
}

if ( empty( $hero_posts ) ) {
	return;
}

$hero_mode  = Hero::sanitize( get_theme_mod( 'kk_hero_mode', Hero::GRID_LEFT ) );
$is_slider  = Hero::isSlider( $hero_mode );
$has_left   = Hero::hasLeftWidget( $hero_mode );
$has_right  = Hero::hasRightWidget( $hero_mode );
// A widget area with no widgets renders nothing: the section
// drops the empty column (via the no-left/right-widget modifier)
// so the hero takes the full width instead.
$left_active  = $has_left && is_active_sidebar( 'kk-hero-left' );
$right_active = $has_right && is_active_sidebar( 'kk-hero-right' );
$section_class = 'kk-hero kk-hero--' . $hero_mode;
if ( $has_left && ! $left_active ) {
	$section_class .= ' kk-hero--no-left-widget';
}
if ( $has_right && ! $right_active ) {
	$section_class .= ' kk-hero--no-right-widget';
}
$lead       = array_shift( $hero_posts );
$rest       = $hero_posts;
// Grid Center: the lead sits in the middle, cards on both sides.
$rest_left  = [];
$rest_right = [];
if ( Hero::GRID_CENTER === $hero_mode && ! empty( $rest ) ) {
	$half       = (int) ceil( count( $rest ) / 2 );
	$rest_left  = array_slice( $rest, 0, $half );
	$rest_right = array_slice( $rest, $half );
}
?>

<section class="<?php echo esc_attr( $section_class ); ?>" aria-label="<?php esc_attr_e( 'Top stories', 'koperasikita' ); ?>">
	<?php if ( $left_active ) : ?>
		<aside class="kk-hero-widget kk-hero-widget--left" aria-label="<?php esc_attr_e( 'Hero left widget', 'koperasikita' ); ?>">
			<?php dynamic_sidebar( 'kk-hero-left' ); ?>
		</aside>
	<?php endif; ?>

	<div class="kk-hero-main">
		<?php if ( $is_slider ) : ?>
			<div class="kk-section-head kk-hero-head">
				<div class="kk-slider-controls">
					<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous story', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next story', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
			</div>
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
			<?php if ( Hero::GRID_CENTER === $hero_mode && ! empty( $rest_left ) ) : ?>
				<div class="kk-hero-grid">
					<?php
					foreach ( $rest_left as $post ) :
						setup_postdata( $post );
						get_template_part( 'template-parts/content/card-news', null, ['hero_card' => true] );
					endforeach;
					?>
				</div>
			<?php endif; ?>

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

			<?php if ( Hero::GRID_CENTER === $hero_mode ) : ?>
				<?php if ( ! empty( $rest_right ) ) : ?>
					<div class="kk-hero-grid">
						<?php
						foreach ( $rest_right as $post ) :
							setup_postdata( $post );
							get_template_part( 'template-parts/content/card-news', null, ['hero_card' => true] );
						endforeach;
						?>
					</div>
				<?php endif; ?>
			<?php elseif ( ! empty( $rest ) ) : ?>
				<div class="kk-hero-grid">
					<?php
					foreach ( $rest as $post ) :
						setup_postdata( $post );
						get_template_part( 'template-parts/content/card-news', null, ['hero_card' => true] );
					endforeach;
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php wp_reset_postdata(); ?>
	</div>

	<?php if ( $right_active ) : ?>
		<aside class="kk-hero-widget kk-hero-widget--right" aria-label="<?php esc_attr_e( 'Hero right widget', 'koperasikita' ); ?>">
			<?php dynamic_sidebar( 'kk-hero-right' ); ?>
		</aside>
	<?php endif; ?>
</section>

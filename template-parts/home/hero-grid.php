<?php
/**
 * Hero: lead story + cards (grid modes) or a one-at-a-time slider
 * (slider modes), optionally paired with the Hero Left / Hero Right
 * widget areas (Appearance -> Widgets).
 *
 * The Hero Presentation setting (kk_hero_mode) picks one of 12
 * modes: Grid Left / Right / Center, Grid + Widget, Leads +
 * Widgets, Slider One + Widget, Slider Full, Slider Center, and
 * Slider + Widget (1/3-2/3). The hero renders with NO heading,
 * exactly as before - the section headings belong to the sections
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
// Leads Widgets keeps both side columns even when the areas are
// empty, so the leads stay centered in the middle column; every
// other widget mode drops the empty column (via the
// no-left/right-widget modifier) so the hero takes the full width.
$keep_widgets = Hero::LEADS_WIDGETS === $hero_mode;
$left_active  = $has_left && ( $keep_widgets || is_active_sidebar( 'kk-hero-left' ) );
$right_active = $has_right && ( $keep_widgets || is_active_sidebar( 'kk-hero-right' ) );
$section_class = 'kk-hero kk-hero--' . $hero_mode;
if ( $has_left && ! $left_active ) {
	$section_class .= ' kk-hero--no-left-widget';
}
if ( $has_right && ! $right_active ) {
	$section_class .= ' kk-hero--no-right-widget';
}
// Bottom Border: the rule under the hero (Classic / No Border /
// Double). Defaults to Classic.
$bottom_border = (string) get_theme_mod( 'kk_hero_bottom_border', 'classic' );
$bottom_border = in_array( $bottom_border, ['classic', 'no-border', 'double'], true ) ? $bottom_border : 'classic';
$section_class .= ' kk-hero--border-' . $bottom_border;
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
// Slider settings: style (Classic / Inner), which parts show, and
// auto-slide with an interval. Only used when the mode is a slider.
$slider_style = 'classic';
$slider_show_category = true;
$slider_show_date = true;
$slider_show_excerpt = true;
$slider_auto = false;
$slider_interval_ms = 5000;
if ( $is_slider ) {
	$slider_style = (string) get_theme_mod( 'kk_hero_slider_style', 'classic' );
	$slider_style = in_array( $slider_style, ['classic', 'inner'], true ) ? $slider_style : 'classic';
	$slider_show_category = (bool) get_theme_mod( 'kk_hero_slider_show_category', true );
	$slider_show_date = (bool) get_theme_mod( 'kk_hero_slider_show_date', true );
	$slider_show_excerpt = (bool) get_theme_mod( 'kk_hero_slider_show_excerpt', true );
	$slider_auto = (bool) get_theme_mod( 'kk_hero_slider_auto', false );
	$slider_interval_ms = min( 30000, max( 2000, (int) get_theme_mod( 'kk_hero_slider_interval', 5 ) * 1000 ) );
	if ( 'inner' === $slider_style ) {
		$section_class .= ' kk-hero--slider-inner';
	}
}
// Lead settings (grid modes): title inside the image or below it,
// and which parts show.
$lead_title_position = 'below';
$lead_show_category = true;
$lead_show_date = true;
$lead_show_excerpt = true;
if ( ! $is_slider ) {
	$lead_title_position = (string) get_theme_mod( 'kk_hero_lead_title_position', 'below' );
	$lead_title_position = in_array( $lead_title_position, ['below', 'inside'], true ) ? $lead_title_position : 'below';
	$lead_show_category = (bool) get_theme_mod( 'kk_hero_lead_show_category', true );
	$lead_show_date = (bool) get_theme_mod( 'kk_hero_lead_show_date', true );
	$lead_show_excerpt = (bool) get_theme_mod( 'kk_hero_lead_show_excerpt', true );
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
			<div class="kk-hero-slider" data-kk-slider<?php echo $slider_auto ? ' data-kk-auto-slide="' . esc_attr( (string) $slider_interval_ms ) . '"' : ''; ?>>
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
							if ( $slider_show_category && ! empty( $categories ) ) {
								?>
								<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
								<?php
							}
							?>
							<h2 class="kk-hero-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<?php if ( $slider_show_excerpt ) : ?>
								<p class="kk-hero-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
							<?php endif; ?>
							<?php if ( $slider_show_date ) : ?>
								<p class="kk-meta">
									<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
								</p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<?php if ( Hero::GRID_CENTER === $hero_mode && ! empty( $rest_left ) ) : ?>
				<ul class="kk-hero-side-list">
					<?php
					foreach ( $rest_left as $post ) :
						setup_postdata( $post );
						get_template_part( 'template-parts/content/hero-side-item' );
					endforeach;
					?>
				</ul>
			<?php endif; ?>

			<?php
			// Lead story.
			$post = $lead;
			setup_postdata( $post );
			?>
			<article class="kk-hero-lead<?php echo 'inside' === $lead_title_position ? ' kk-hero-lead--title-inside' : ''; ?>">
				<a class="kk-hero-lead-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
					<img src="<?php echo esc_url( \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' ) ); ?>" alt="" loading="eager" fetchpriority="high">
				</a>
				<div class="kk-hero-lead-body">
					<?php
					$categories = get_the_category();
					if ( $lead_show_category && ! empty( $categories ) ) {
						?>
						<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
						<?php
					}
					?>
					<h2 class="kk-hero-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php if ( $lead_show_excerpt ) : ?>
						<p class="kk-hero-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>
					<?php if ( $lead_show_date ) : ?>
						<p class="kk-meta">
							<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
						</p>
					<?php endif; ?>
				</div>
			</article>

			<?php if ( Hero::GRID_CENTER === $hero_mode ) : ?>
				<?php if ( ! empty( $rest_right ) ) : ?>
					<ul class="kk-hero-side-list">
						<?php
						foreach ( $rest_right as $post ) :
							setup_postdata( $post );
							get_template_part( 'template-parts/content/hero-side-item' );
						endforeach;
						?>
					</ul>
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

<?php
/**
 * Highlights ("Sorotan"): editor-picked posts filtered by category
 * and/or tag, with a custom title, a post count, an item style
 * (thumb inside / thumb below / title+meta), a layout style
 * (classic / no border / box) and a display (grid scrollable /
 * list scrollable / slider auto / slider manual). Empty filters
 * fall back to the latest posts, so the section never shows a hole.
 * Hidden entirely when the toggle is off or no posts exist.
 *
 * @package KoperasiKita
 */

if ( ! (bool) get_theme_mod( 'kk_highlights_enabled', true ) ) {
	return;
}

$highlights_posts = \KoperasiKita\Features\Homepage::highlightsPosts();

if ( empty( $highlights_posts ) ) {
	return;
}

$show_title    = (bool) get_theme_mod( 'kk_highlights_show_title', true );
$highlights_title = (string) get_theme_mod( 'kk_highlights_title', __( 'Sorotan', 'koperasikita' ) );
$highlights_title = trim( $highlights_title );
$item_style    = (string) get_theme_mod( 'kk_highlights_item_style', 'thumb-below' );
$item_style    = in_array( $item_style, ['thumb-inside', 'thumb-below', 'title-meta'], true ) ? $item_style : 'thumb-below';
$layout_style  = (string) get_theme_mod( 'kk_highlights_layout_style', 'classic' );
$layout_style  = in_array( $layout_style, ['classic', 'no-border', 'box'], true ) ? $layout_style : 'classic';
// Display: the new setting; fall back to the old Grid/Slider
// layout for installs that set it before the Display setting
// existed (grid -> Grid Scrollable, slider -> Slider manual).
$display = get_theme_mod( 'kk_highlights_display', false );
if ( false === $display || ! in_array( $display, ['grid-scrollable', 'list-scrollable', 'slider-auto', 'slider-manual'], true ) ) {
	$old_layout = (string) get_theme_mod( 'kk_highlights_layout', 'grid' );
	$display    = 'slider' === $old_layout ? 'slider-manual' : 'grid-scrollable';
}
$is_slider = in_array( $display, ['slider-auto', 'slider-manual'], true );
$is_list   = 'list-scrollable' === $display;
?>

<section class="kk-highlights kk-highlights--item-<?php echo esc_attr( $item_style ); ?> kk-highlights--layout-<?php echo esc_attr( $layout_style ); ?> kk-highlights--display-<?php echo esc_attr( $display ); ?>" aria-labelledby="kk-highlights-heading">
	<div class="kk-section-head">
		<?php if ( $show_title && '' !== $highlights_title ) : ?>
			<h2 class="kk-section-title" id="kk-highlights-heading"><?php echo esc_html( $highlights_title ); ?></h2>
		<?php endif; ?>
		<?php if ( 'slider-manual' === $display ) : ?>
			<div class="kk-slider-controls">
				<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $is_slider ) : ?>
		<div class="kk-highlights-slider" data-kk-slider<?php echo 'slider-auto' === $display ? ' data-kk-auto-slide="5000"' : ''; ?>>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news' );
			endforeach;
			?>
		</div>
	<?php elseif ( $is_list ) : ?>
		<div class="kk-highlights-scroll kk-highlights-scroll--list" data-kk-slider>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news' );
			endforeach;
			?>
		</div>
	<?php else : ?>
		<div class="kk-highlights-scroll" data-kk-slider>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news' );
			endforeach;
			?>
		</div>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</section>

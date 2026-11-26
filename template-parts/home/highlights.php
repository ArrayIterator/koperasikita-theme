<?php
/**
 * Highlights ("Sorotan"): editor-picked posts filtered by category
 * and/or tag, with a custom title, a post count, an item style
 * (thumb inside / thumb below / title+meta), a layout style
 * (classic / no border / box) and a display:
 *
 * - Slider Grid: grid look, swipeable with prev/next buttons; it
 *   never autoplays unless Slider Grid Autoplay is on (then the
 *   interval applies).
 * - Slider Full: one article per slide, a small thumbnail like a
 *   widget row then the title; autoplay optional, with interval.
 * - List: widget-like rows split into columns (List Columns) with
 *   List Rows per column; List Enable Slider makes the columns
 *   slide (prev/next + touch) instead of sitting still.
 *
 * Empty filters fall back to the latest posts, so the section never
 * shows a hole. Hidden entirely when the toggle is off or no posts
 * exist.
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
// Display, mapped from the legacy values so old installs keep a
// sensible display (see Customizer::highlightsDisplay()).
$display = \KoperasiKita\Customizer\Customizer::highlightsDisplay();

$auto_attr = '';
$show_controls = false;
if ( 'slider-grid' === $display ) {
	$show_controls = true;
	if ( (bool) get_theme_mod( 'kk_highlights_slider_grid_autoplay', false ) ) {
		$interval = (int) get_theme_mod( 'kk_highlights_slider_grid_interval', 5 );
		$interval = max( 2, min( 30, $interval ) );
		$auto_attr = ' data-kk-auto-slide="' . ( $interval * 1000 ) . '"';
	}
} elseif ( 'slider-full' === $display ) {
	$show_controls = true;
	if ( (bool) get_theme_mod( 'kk_highlights_slider_full_autoplay', false ) ) {
		$interval = (int) get_theme_mod( 'kk_highlights_slider_full_interval', 5 );
		$interval = max( 2, min( 30, $interval ) );
		$auto_attr = ' data-kk-auto-slide="' . ( $interval * 1000 ) . '"';
	}
} elseif ( 'list' === $display ) {
	$show_controls = (bool) get_theme_mod( 'kk_highlights_list_enable_slider', false );
}

// List: split the posts into columns, each column holding
// List Rows posts (column 1 gets the first rows, column 2 the
// next rows, and so on). Only as many columns as the posts fill.
$list_columns = 2;
$list_rows = 3;
$list_groups = [];
if ( 'list' === $display ) {
	$list_columns = (int) get_theme_mod( 'kk_highlights_list_columns', 2 );
	$list_columns = max( 1, min( 4, $list_columns ) );
	$list_rows = (int) get_theme_mod( 'kk_highlights_list_rows', 3 );
	$list_rows = max( 1, min( 10, $list_rows ) );
	$list_groups = array_chunk( $highlights_posts, $list_rows );
	$list_groups = array_slice( $list_groups, 0, $list_columns );
}
?>

<section class="kk-highlights kk-highlights--item-<?php echo esc_attr( $item_style ); ?> kk-highlights--layout-<?php echo esc_attr( $layout_style ); ?> kk-highlights--display-<?php echo esc_attr( $display ); ?>" aria-labelledby="kk-highlights-heading">
	<div class="kk-section-head">
		<?php if ( $show_title && '' !== $highlights_title ) : ?>
			<h2 class="kk-section-title" id="kk-highlights-heading"><?php echo esc_html( $highlights_title ); ?></h2>
		<?php endif; ?>
		<?php if ( $show_controls ) : ?>
			<div class="kk-slider-controls">
				<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 'slider-full' === $display ) : ?>
		<div class="kk-highlights-slider-full" data-kk-slider<?php echo $auto_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				?>
				<div class="kk-highlights-full-slide">
					<?php get_template_part( 'template-parts/content/highlight-row' ); ?>
				</div>
				<?php
			endforeach;
			?>
		</div>
	<?php elseif ( 'list' === $display ) : ?>
		<div class="kk-highlights-list<?php echo $show_controls ? ' kk-highlights-list--slider' : ''; ?>"<?php echo $show_controls ? ' data-kk-slider' : ''; ?>>
			<?php foreach ( $list_groups as $group ) : ?>
				<ul class="kk-highlights-list-column">
					<?php
					foreach ( $group as $post ) :
						setup_postdata( $post );
						get_template_part( 'template-parts/content/highlight-row' );
					endforeach;
					?>
				</ul>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="kk-highlights-slider" data-kk-slider<?php echo $auto_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news', null, ['highlights_card' => true] );
			endforeach;
			?>
		</div>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</section>

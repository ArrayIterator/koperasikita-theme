<?php
/**
 * The front page template.
 *
 * Hero grid, editor picks, latest news and category blocks.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-home">
	<?php get_template_part( 'template-parts/content/breadcrumb' ); ?>

	<?php
	// Hero + Highlights (Sorotan) exist ONLY on the front page,
	// page 1. WordPress reuses front-page.php for the paginated
	// front page (/page/2/, /page/3/, ...) too, so without this
	// guard the hero and highlights repeat on every page of the
	// post list. is_paged() is true from page 2 on.
	if ( ! is_paged() ) :
		?>

		<?php get_template_part( 'template-parts/home/hero-grid' ); ?>

		<?php
		// Before Highlights widget area: full-width strip directly
		// before the Highlights (Sorotan) section. Renders nothing
		// when empty.
		if ( is_active_sidebar( 'kk-before-highlights' ) ) :
			?>
			<div class="kk-widget-area kk-widget-area--before-highlights">
				<?php dynamic_sidebar( 'kk-before-highlights' ); ?>
			</div>
			<?php
		endif;
		?>

		<?php get_template_part( 'template-parts/home/highlights' ); ?>

	<?php endif; ?>

	<?php
	// Block Area widget (global, locked): ONE shared area for the
	// whole category-block region, not one per block. Whether it
	// has a widget or not, the homepage sidebar always sits beside
	// the primary content on desktop - an empty Block Area must
	// never collapse the layout to a single column.
	$kk_has_block_widget = is_active_sidebar( 'kk-block' );
	?>
	<div class="kk-home-layout">
		<div class="kk-home-primary">
			<?php
			// Before Latest News widget area: full-width strip
			// directly before the Latest News section. Renders
			// nothing when empty.
			if ( is_active_sidebar( 'kk-before-latest' ) ) :
				?>
				<div class="kk-widget-area kk-widget-area--before-latest">
					<?php dynamic_sidebar( 'kk-before-latest' ); ?>
				</div>
				<?php
			endif;
			?>

			<?php get_template_part( 'template-parts/home/latest-news' ); ?>

			<?php
			// After Latest News widget area: full-width strip directly
			// after the Latest News section. Renders nothing when empty.
			if ( is_active_sidebar( 'kk-after-latest' ) ) :
				?>
				<div class="kk-widget-area kk-widget-area--after-latest">
					<?php dynamic_sidebar( 'kk-after-latest' ); ?>
				</div>
				<?php
			endif;
			?>

			<?php
			// Category blocks: the three Customizer slots, in order.
			// Each block is followed by its own After Category Block
			// widget area - but only when that block is active. The
			// loop only visits active blocks, so an inactive block's
			// strip never renders.
			foreach ( \KoperasiKita\Features\Homepage::categoryBlocks() as $block ) {
				set_query_var( 'kk_category_block', $block['term'] );
				set_query_var( 'kk_category_block_slot', $block['slot'] );
				get_template_part( 'template-parts/home/category-block' );

				$after_block_area = 'kk-after-category-block-' . $block['slot'];
				if ( is_active_sidebar( $after_block_area ) ) :
					?>
					<div class="kk-widget-area kk-widget-area--after-category-block-<?php echo esc_attr( (string) $block['slot'] ); ?>">
						<?php dynamic_sidebar( $after_block_area ); ?>
					</div>
					<?php
				endif;
			}
			?>

			<?php
			// Block Area widget: the global, locked widget for the
			// category-block region. Renders nothing when empty.
			if ( $kk_has_block_widget ) :
				?>
				<div class="kk-widget-area kk-widget-area--block">
					<?php dynamic_sidebar( 'kk-block' ); ?>
				</div>
				<?php
			endif;
			?>
		</div>

		<div class="kk-home-secondary">
			<?php get_sidebar( 'news' ); ?>
		</div>
	</div>

	<?php
	// Home Bottom widget area: full-width strip directly after the
	// homepage layout, at the bottom of the shell. Renders nothing
	// when empty. It renders on paginated front pages too, like the
	// layout content (no is_paged() guard).
	if ( is_active_sidebar( 'kk-home-bottom' ) ) :
		?>
		<div class="kk-widget-area kk-widget-area--home-bottom">
			<?php dynamic_sidebar( 'kk-home-bottom' ); ?>
		</div>
		<?php
	endif;
	?>
</div>

<?php
get_footer();

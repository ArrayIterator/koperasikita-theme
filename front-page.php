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

	<?php
	// Block Area widget (global, locked): ONE shared area for the
	// whole category-block region, not one per block. When it has
	// no widget, the homepage sidebar drops below the primary
	// content instead of sitting beside it.
	$kk_has_block_widget = is_active_sidebar( 'kk-block' );
	?>
	<div class="kk-home-layout<?php echo $kk_has_block_widget ? '' : ' kk-home--no-block-widget'; ?>">
		<div class="kk-home-primary">
			<?php
			// Market strip: gold spot, stocks and crypto above the latest
			// news. Rendered only when the Markets feature is on and the
			// homepage toggle (Customizer -> Homepage Layout) allows it;
			// the strip template shows an honest empty state until prices
			// are entered in Settings -> Markets.
			$kk_strip_markets = \KoperasiKita\Features\Markets::homepageStripMarkets();
			if ($kk_strip_markets !== []) {
				get_template_part('template-parts/markets/strip', null, [
					'markets' => $kk_strip_markets,
					'show_change' => true,
				]);
			}
			?>

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
			// widget area — but only when that block is active. The
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
</div>

<?php
get_footer();

<?php
/**
 * One category block: eyebrow + title + the posts in the slot's
 * display style.
 *
 * Display styles (per slot):
 * - Grid: a uniform grid of cards.
 * - List: flat list rows.
 * - Featured: first post large on the LEFT, the rest as a
 *   descending list of compact rows on the right.
 * - Featured Right: first post large on the RIGHT, the rest as a
 *   descending list of compact rows on the left.
 * - List (Columns): widget-like rows split into columns (List
 *   Columns) with List Rows per column; List Enable Slider makes
 *   the columns slide (prev/next + touch) instead of sitting
 *   still.
 *
 * Accepts $args['category'] (WP_Term) or the query var kk_category_block.
 *
 * @package KoperasiKita
 */

$category = null;

if ( isset( $args['category'] ) && $args['category'] instanceof WP_Term ) {
	$category = $args['category'];
} else {
	$category = get_query_var( 'kk_category_block' );
}

if ( ! $category instanceof WP_Term ) {
	return;
}

// The slot this block came from (1–3); the count and display style
// are per-slot, so each block is independent of the others.
$slot = (int) get_query_var( 'kk_category_block_slot', 1 );
$slot = min( 3, max( 1, $slot ) );
$block_count = \KoperasiKita\Features\Homepage::categoryBlockCount( $slot );
$block_layout = \KoperasiKita\Features\Homepage::categoryBlockLayout( $slot );

$block_query = new WP_Query(
	array(
		'post_type'           => 'post',
		'posts_per_page'      => $block_count,
		'cat'                 => $category->term_id,
		'ignore_sticky_posts' => true,
	)
);

if ( ! $block_query->have_posts() ) {
	return;
}

// Featured Right reuses the Featured grid (first card large, rest
// as rows) and only flips which side the lead sits on, via an
// extra class the CSS uses to mirror the columns.
$items_class = 'kk-layout-' . $block_layout;
if ( 'featured-right' === $block_layout ) {
	$items_class = 'kk-layout-featured kk-layout-featured-right';
}

// List (Columns): split the posts into columns, each column
// holding List Rows posts (column 1 gets the first rows, column 2
// the next rows, and so on). Only as many columns as the posts
// fill.
$list_groups = [];
$list_enable_slider = false;
if ( 'list-columns' === $block_layout ) {
	$list_settings = \KoperasiKita\Features\Homepage::categoryBlockListSettings( $slot );
	$list_enable_slider = $list_settings['enable_slider'];
	$list_groups = array_chunk( $block_query->posts, $list_settings['rows'] );
	$list_groups = array_slice( $list_groups, 0, $list_settings['columns'] );
}
?>

<section class="kk-category-block" aria-labelledby="kk-cat-<?php echo esc_attr( $category->term_id ); ?>">
	<div class="kk-section-head">
		<p class="kk-eyebrow"><?php esc_html_e( 'Section', 'koperasikita' ); ?></p>
		<h2 class="kk-section-title" id="kk-cat-<?php echo esc_attr( $category->term_id ); ?>">
			<a href="<?php echo esc_url( get_category_link( $category ) ); ?>"><?php echo esc_html( $category->name ); ?></a>
		</h2>
		<?php if ( 'list-columns' === $block_layout && $list_enable_slider ) : ?>
			<div class="kk-slider-controls">
				<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 'list-columns' === $block_layout ) : ?>
		<div class="kk-category-block-items <?php echo esc_attr( $items_class ); ?><?php echo $list_enable_slider ? ' kk-category-block-list--slider' : ''; ?>"<?php echo $list_enable_slider ? ' data-kk-slider' : ''; ?>>
			<?php foreach ( $list_groups as $group ) : ?>
				<ul class="kk-category-block-list-column">
					<?php
					foreach ( $group as $post ) :
						setup_postdata( $post );
						get_template_part( 'template-parts/content/category-row' );
					endforeach;
					?>
				</ul>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="kk-category-block-items <?php echo esc_attr( $items_class ); ?>">
			<?php
			while ( $block_query->have_posts() ) :
				$block_query->the_post();
				get_template_part( 'template-parts/content/card-news' );
			endwhile;
			?>
		</div>
	<?php endif; ?>

	<p class="kk-more-link">
		<a href="<?php echo esc_url( get_category_link( $category ) ); ?>">
			<?php
			/* translators: %s: Category name. */
			printf( esc_html__( 'More in %s', 'koperasikita' ), esc_html( $category->name ) );
			?>
			<?php echo \KoperasiKita\Icons\Icon::svg( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</a>
	</p>
</section>

<?php
wp_reset_postdata();

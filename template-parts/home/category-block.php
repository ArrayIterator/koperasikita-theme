<?php
/**
 * One category block: eyebrow + title + a uniform grid of cards.
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
?>

<section class="kk-category-block" aria-labelledby="kk-cat-<?php echo esc_attr( $category->term_id ); ?>">
	<div class="kk-section-head">
		<p class="kk-eyebrow"><?php esc_html_e( 'Section', 'koperasikita' ); ?></p>
		<h2 class="kk-section-title" id="kk-cat-<?php echo esc_attr( $category->term_id ); ?>">
			<a href="<?php echo esc_url( get_category_link( $category ) ); ?>"><?php echo esc_html( $category->name ); ?></a>
		</h2>
	</div>

	<div class="kk-category-block-items kk-layout-<?php echo esc_attr( $block_layout ); ?>">
		<?php
		while ( $block_query->have_posts() ) :
			$block_query->the_post();
			get_template_part( 'template-parts/content/card-news' );
		endwhile;
		?>
	</div>

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

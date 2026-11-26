<?php
/**
 * Highlights ("Sorotan"): editor-picked posts filtered by category
 * and/or tag, with a custom title and a Grid/Slider layout. Empty
 * filters fall back to the latest posts, so the section never shows
 * a hole. Hidden entirely when the toggle is off or no posts exist.
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

$highlights_title = (string) get_theme_mod( 'kk_highlights_title', __( 'Sorotan', 'koperasikita' ) );
$highlights_title = trim( $highlights_title );
$highlights_layout = (string) get_theme_mod( 'kk_highlights_layout', 'grid' );
$highlights_layout = in_array( $highlights_layout, ['grid', 'slider'], true ) ? $highlights_layout : 'grid';
?>

<section class="kk-highlights kk-highlights--<?php echo esc_attr( $highlights_layout ); ?>" aria-labelledby="kk-highlights-heading">
	<div class="kk-section-head">
		<?php if ( '' !== $highlights_title ) : ?>
			<h2 class="kk-section-title" id="kk-highlights-heading"><?php echo esc_html( $highlights_title ); ?></h2>
		<?php endif; ?>
		<?php if ( 'slider' === $highlights_layout ) : ?>
			<div class="kk-slider-controls">
				<button type="button" class="kk-icon-button kk-slider-prev" aria-label="<?php esc_attr_e( 'Previous highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="kk-icon-button kk-slider-next" aria-label="<?php esc_attr_e( 'Next highlight', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( 'slider' === $highlights_layout ) : ?>
		<div class="kk-highlights-slider" data-kk-slider>
			<?php
			foreach ( $highlights_posts as $post ) :
				setup_postdata( $post );
				get_template_part( 'template-parts/content/card-news' );
			endforeach;
			?>
		</div>
	<?php else : ?>
		<div class="kk-card-grid">
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

<?php
/**
 * Pagination for archive loops.
 *
 * Three modes (see Features\Pagination and the News Pagination
 * setting in Appearance & Accessibility):
 * - Numbered Pagination: page numbers (default).
 * - Load More: the load-more button (the loader fetches the next
 *   page via the /posts endpoint).
 * - Next Prev: only Previous / Next links, no page numbers.
 *
 * @package KoperasiKita
 */

$mode = \KoperasiKita\Features\Pagination::mode();

if ( 'load-more' === $mode ) {
	\KoperasiKita\Features\Pagination::renderLoader();

	return;
}

if ( 'next-prev' === $mode ) {
	$prev = get_previous_posts_link( __( '&larr; Newer', 'koperasikita' ) );
	$next = get_next_posts_link( __( 'Older &rarr;', 'koperasikita' ) );

	if ( ! $prev && ! $next ) {
		return;
	}
	?>
	<nav class="kk-pagination kk-pagination-next-prev" aria-label="<?php esc_attr_e( 'Posts navigation', 'koperasikita' ); ?>">
		<?php if ( $prev ) : ?>
			<span class="kk-pagination-prev"><?php echo $prev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<?php endif; ?>
		<?php if ( $next ) : ?>
			<span class="kk-pagination-next"><?php echo $next; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<?php endif; ?>
	</nav>
	<?php

	return;
}

the_posts_pagination(
	array(
		'mid_size'  => 2,
		'prev_text' => __( '&larr; Newer', 'koperasikita' ),
		'next_text' => __( 'Older &rarr;', 'koperasikita' ),
		'class'     => 'kk-pagination',
	)
);

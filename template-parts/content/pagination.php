<?php
/**
 * Pagination for archive loops.
 *
 * Page numbers by default; the load-more button or the infinite
 * scroll sentinel when the pagination mode says so (see
 * Features\Pagination).
 *
 * @package KoperasiKita
 */

if ( 'pagination' !== \KoperasiKita\Features\Pagination::mode() ) {
	\KoperasiKita\Features\Pagination::renderLoader();

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

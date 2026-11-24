<?php
/**
 * Pagination for archive loops.
 *
 * @package KoperasiKita
 */

the_posts_pagination(
	array(
		'mid_size'  => 2,
		'prev_text' => __( '&larr; Newer', 'koperasikita' ),
		'next_text' => __( 'Older &rarr;', 'koperasikita' ),
		'class'     => 'kk-pagination',
	)
);

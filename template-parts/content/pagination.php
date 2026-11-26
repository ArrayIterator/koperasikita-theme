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
 * Accepts $args['query'] (WP_Query): paginate that query instead
 * of the main query (the homepage latest news passes its own
 * query). Accepts $args['mode'] to override the mode (the homepage
 * falls back to numbered pagination for load-more in the
 * newsletter layout, which has no grid to append cards to).
 *
 * The After Pagination widget area renders after the pagination
 * on every page that uses this part, even when there is only one
 * page and no pagination links — so the area is always available.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Features\Pagination;

$pagination_query = ( isset( $args['query'] ) && $args['query'] instanceof WP_Query ) ? $args['query'] : null;
$mode = ( isset( $args['mode'] ) && in_array( $args['mode'], Pagination::modes(), true ) ) ? $args['mode'] : Pagination::mode();

if ( 'load-more' === $mode ) {
	Pagination::renderLoader( $pagination_query );
} elseif ( 'next-prev' === $mode ) {
	if ( $pagination_query ) {
		$paged = max( 1, (int) $pagination_query->get( 'paged' ) );
		$max_pages = max( 1, (int) $pagination_query->max_num_pages );
		$prev_url = $paged > 1 ? get_pagenum_link( $paged - 1 ) : '';
		$next_url = $paged < $max_pages ? get_pagenum_link( $paged + 1 ) : '';
		if ( $prev_url || $next_url ) {
			?>
			<nav class="kk-pagination kk-pagination-next-prev" aria-label="<?php esc_attr_e( 'Posts navigation', 'koperasikita' ); ?>">
				<?php if ( $prev_url ) : ?>
					<span class="kk-pagination-prev"><a href="<?php echo esc_url( $prev_url ); ?>"><?php echo Pagination::prevText(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></span>
				<?php endif; ?>
				<?php if ( $next_url ) : ?>
					<span class="kk-pagination-next"><a href="<?php echo esc_url( $next_url ); ?>"><?php echo Pagination::nextText(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></span>
				<?php endif; ?>
			</nav>
			<?php
		}
	} else {
		$prev = get_previous_posts_link( Pagination::prevText() );
		$next = get_next_posts_link( Pagination::nextText() );

		if ( $prev || $next ) {
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
		}
	}
} elseif ( $pagination_query ) {
	$paged = max( 1, (int) $pagination_query->get( 'paged' ) );
	$max_pages = max( 1, (int) $pagination_query->max_num_pages );

	if ( $max_pages > 1 ) {
		$links = paginate_links(
			array(
				'base'      => str_replace( '999999999', '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
				'format'    => '?paged=%#%',
				'current'   => $paged,
				'total'     => $max_pages,
				'mid_size'  => 2,
				'prev_text' => Pagination::prevText(),
				'next_text' => Pagination::nextText(),
				'type'      => 'plain',
			)
		);

		if ( $links ) {
			?>
			<nav class="navigation pagination kk-pagination" aria-label="<?php esc_attr_e( 'Posts navigation', 'koperasikita' ); ?>">
				<h2 class="screen-reader-text"><?php esc_html_e( 'Posts navigation', 'koperasikita' ); ?></h2>
				<div class="nav-links"><?php echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</nav>
			<?php
		}
	}
} else {
	the_posts_pagination(
		array(
			'mid_size'  => 2,
			'prev_text' => Pagination::prevText(),
			'next_text' => Pagination::nextText(),
			'class'     => 'kk-pagination',
		)
	);
}

// After Pagination widget area: every page that shows a post list
// (homepage, archives, categories, search). Renders nothing when
// empty.
if ( is_active_sidebar( 'kk-after-pagination' ) ) :
	?>
	<div class="kk-widget-area kk-widget-area--after-pagination">
		<?php dynamic_sidebar( 'kk-after-pagination' ); ?>
	</div>
	<?php
endif;

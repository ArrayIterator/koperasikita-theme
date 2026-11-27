<?php
/**
 * The search results template.
 *
 * Results use the Main Sidebar (Archive & Search). No results use
 * the 404 Sidebar: when it has widgets the page is two-column (half
 * page); when empty it is full width. No hardcoded latest-news list.
 *
 * @package KoperasiKita
 */

get_header();

$kk_no_results = ! have_posts();
$kk_sidebar_id = $kk_no_results ? 'sidebar-404' : 'sidebar-1';
$kk_has_sidebar = is_active_sidebar( $kk_sidebar_id );
?>

<div class="kk-shell<?php echo $kk_has_sidebar ? ' kk-archive-layout' : ''; ?>">
	<div class="kk-content-area">
		<?php get_template_part( 'template-parts/content/breadcrumb' ); ?>

		<header class="kk-search-header">
			<p class="kk-eyebrow"><?php esc_html_e( 'Search results', 'koperasikita' ); ?></p>
			<h1 class="kk-page-title">
				<?php
				/* translators: %s: Search query. */
				printf( esc_html__( 'Results for "%s"', 'koperasikita' ), esc_html( get_search_query() ) );
				?>
			</h1>
			<?php if ( ! $kk_no_results ) : ?>
				<p class="kk-search-count">
					<?php
					/* translators: %s: Number of search results. No plural
					 * form: the count stands on its own, so one string
					 * covers every count in every language. */
					$kk_found = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ? (int) $GLOBALS['wp_query']->found_posts : 0;
					printf( esc_html__( 'Results: %s', 'koperasikita' ), esc_html( number_format_i18n( $kk_found ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php get_search_form(); ?>
		</header>

		<?php if ( ! $kk_no_results ) : ?>
			<div class="kk-card-grid kk-layout-<?php echo esc_attr( \KoperasiKita\Features\Pagination::archiveLayout() ); ?>">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/content/card-news' );
				endwhile;
				?>
			</div>

			<?php get_template_part( 'template-parts/content/pagination' ); ?>

		<?php else : ?>
			<section class="kk-no-results">
				<h2 class="kk-section-title"><?php esc_html_e( 'No results found', 'koperasikita' ); ?></h2>
				<p><?php esc_html_e( 'Try different keywords, or use the search form above.', 'koperasikita' ); ?></p>
			</section>
		<?php endif; ?>
	</div>

	<?php
	if ( $kk_has_sidebar ) {
		if ( $kk_no_results ) {
			get_sidebar( '404' );
		} else {
			get_sidebar();
		}
	}
	?>
</div>

<?php
get_footer();

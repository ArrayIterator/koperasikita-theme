<?php
/**
 * The search results template.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-archive-layout">
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
			<?php get_search_form(); ?>
		</header>

		<?php if ( have_posts() ) : ?>
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
				<p><?php esc_html_e( 'Try different keywords, or browse the latest news below.', 'koperasikita' ); ?></p>
				<?php
				$latest = new WP_Query(
					array(
						'post_type'      => 'post',
						'posts_per_page' => 5,
					)
				);

				if ( $latest->have_posts() ) :
					?>
					<div class="kk-no-results-latest kk-card-grid kk-layout-list">
						<?php
						while ( $latest->have_posts() ) :
							$latest->the_post();
							get_template_part( 'template-parts/content/card-news' );
						endwhile;
						?>
					</div>
					<?php
					wp_reset_postdata();
				endif;
				?>
			</section>
		<?php endif; ?>
	</div>

	<?php get_sidebar(); ?>
</div>

<?php
get_footer();

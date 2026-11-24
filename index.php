<?php
/**
 * The main template file (archive fallback).
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-archive-layout">
	<div class="kk-content-area">
		<?php get_template_part( 'template-parts/content/breadcrumb' ); ?>

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
				<h1 class="kk-page-title"><?php esc_html_e( 'Nothing found', 'koperasikita' ); ?></h1>
				<p><?php esc_html_e( 'No posts were found. Try a different search.', 'koperasikita' ); ?></p>
				<?php get_search_form(); ?>
			</section>
		<?php endif; ?>
	</div>

	<?php get_sidebar(); ?>
</div>

<?php
get_footer();

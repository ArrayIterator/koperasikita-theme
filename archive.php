<?php
/**
 * The archive template (category, tag, author, date).
 *
 * @package KoperasiKita
 */

get_header();

// On archive pages the sidebar shows only the widgets the editor
// placed (the Sidebar Menu never counts there), so the two-column
// layout applies only when the Main Sidebar has widgets. When it
// does not, the page is full width - the same rule search.php and
// 404.php already use - instead of leaving an empty sidebar column.
$kk_has_sidebar = is_active_sidebar( 'sidebar-1' );
?>

<div class="kk-shell<?php echo $kk_has_sidebar ? ' kk-archive-layout' : ''; ?>">
	<div class="kk-content-area">
		<?php get_template_part( 'template-parts/content/breadcrumb' ); ?>

		<header class="kk-archive-header">
			<?php
			the_archive_title( '<h1 class="kk-page-title">', '</h1>' );
			the_archive_description( '<div class="kk-archive-description">', '</div>' );
			?>
		</header>

		<?php if ( have_posts() ) : ?>
			<?php get_template_part( 'template-parts/content/archive-layout-switcher' ); ?>

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
				<p><?php esc_html_e( 'No posts were found in this archive.', 'koperasikita' ); ?></p>
				<?php get_search_form(); ?>
			</section>
		<?php endif; ?>
	</div>

	<?php if ( $kk_has_sidebar ) : ?>
		<?php get_sidebar(); ?>
	<?php endif; ?>
</div>

<?php
get_footer();

<?php
/**
 * The 404 template.
 *
 * No hardcoded latest-news list: the 404 page shows its own widget
 * area (404 Sidebar). When that area has widgets the page is
 * two-column (half page); when empty it is full width.
 *
 * @package KoperasiKita
 */

get_header();

$kk_has_404_sidebar = is_active_sidebar( 'sidebar-404' );
?>

<?php if ( $kk_has_404_sidebar ) : ?>
	<div class="kk-shell kk-archive-layout">
		<div class="kk-content-area">
<?php else : ?>
	<div class="kk-shell kk-not-found">
<?php endif; ?>
	<section class="kk-404" aria-labelledby="kk-404-heading">
		<p class="kk-404-code" aria-hidden="true">404</p>
		<h1 class="kk-page-title" id="kk-404-heading"><?php esc_html_e( 'Page not found', 'koperasikita' ); ?></h1>
		<p class="kk-404-text">
			<?php esc_html_e( 'The page you are looking for may have moved, or it no longer exists. Try searching, or go back to the homepage.', 'koperasikita' ); ?>
		</p>

		<?php get_search_form(); ?>

		<p class="kk-404-home">
			<a class="kk-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to homepage', 'koperasikita' ); ?></a>
		</p>
	</section>
<?php if ( $kk_has_404_sidebar ) : ?>
		</div>

		<?php get_sidebar( '404' ); ?>
	</div>
<?php else : ?>
	</div>
<?php endif; ?>

<?php
get_footer();

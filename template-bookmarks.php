<?php
/**
 * Template Name: Bookmarks
 * Description: Lists the reader's saved bookmarks (stored locally in IndexedDB).
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-bookmarks-page">
	<header class="kk-page-header">
		<h1 class="kk-page-title"><?php esc_html_e( 'Bookmarks', 'koperasikita' ); ?></h1>
		<p class="kk-page-intro">
			<?php esc_html_e( 'Articles you saved on this device. Bookmarks are stored only in this browser.', 'koperasikita' ); ?>
		</p>
	</header>

	<?php if ( \KoperasiKita\Features\Features::isEnabled( 'bookmarks' ) ) : ?>
		<div id="kk-bookmarks" class="kk-bookmarks-list" aria-live="polite">
			<!-- Filled by main.ts from the kk-bookmarks IndexedDB store. -->
		</div>

		<div id="kk-bookmarks-empty" class="kk-bookmarks-empty" hidden>
			<p class="kk-bookmarks-empty-title"><?php esc_html_e( 'No bookmarks yet', 'koperasikita' ); ?></p>
			<p><?php esc_html_e( 'Tap the bookmark button on any article to save it here for later reading.', 'koperasikita' ); ?></p>
			<a class="kk-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Browse the latest news', 'koperasikita' ); ?></a>
		</div>
	<?php else : ?>
		<div class="kk-notice kk-notice-info" role="status">
			<p><?php esc_html_e( 'Bookmarks are currently disabled.', 'koperasikita' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();

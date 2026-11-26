<?php
/**
 * The header for the Koperasi Kita theme.
 *
 * Displays the top bar, masthead, primary navigation and news ticker.
 *
 * @package KoperasiKita
 */

?>
<!doctype html>
<html <?php language_attributes(); ?> data-color-scheme="system" data-kk-default-scheme="<?php echo esc_attr( (string) get_theme_mod( 'kk_color_scheme', 'system' ) ); ?>">
<head>
	<script>
	/* Colour scheme, resolved before first paint: read the stored
	 * preference (or the Customizer default scheme, or the OS scheme)
	 * and set data-color-scheme now, so the page never flashes.
	 * main.ts keeps the same preference in sync afterwards and
	 * follows OS changes while it is "system". */
	(function () {
		try {
			var stored = window.localStorage.getItem('kk-color-scheme');
			var fallback = document.documentElement.getAttribute('data-kk-default-scheme') || 'system';
			if (stored !== 'dark' && stored !== 'light' && stored !== 'system') {
				stored = fallback;
			}
			var media = window.matchMedia('(prefers-color-scheme: dark)');
			var resolved = (stored === 'dark' || stored === 'light') ? stored : (media.matches ? 'dark' : 'light');
			document.documentElement.setAttribute('data-color-scheme', resolved);
			/* The stored preference itself, for the toggle icon: while
			 * it is "system" the toggle shows a monitor (the resolved
			 * scheme alone cannot tell system from a pinned mode). */
			document.documentElement.setAttribute('data-kk-scheme', stored);
		} catch (e) { /* private mode / no storage: keep the "system" default */ }
	})();
	</script>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="kk-skip-link" href="#kk-main"><?php esc_html_e( 'Skip to content', 'koperasikita' ); ?></a>

<?php
// The install banner above the header, when the Customizer puts it
// there. It never lives in the sticky nav bar.
if ( \KoperasiKita\Features\Pwa::isButtonPosition( 'above-header' ) ) {
	get_template_part( 'template-parts/pwa/install-button', null, array( 'style' => 'above-header' ) );
}
?>

<header class="kk-site-header" id="kk-top">
	<?php get_template_part( 'template-parts/header/top-bar' ); ?>

	<div class="kk-masthead<?php echo esc_attr( \KoperasiKita\Features\Logo::mastheadClasses() ); ?>">
		<div class="kk-shell kk-masthead-inner">
			<div class="kk-brand<?php echo esc_attr( \KoperasiKita\Features\Logo::brandClasses() ); ?>">
				<?php
				// A custom logo is the site's banner lockup (mark + wordmark
				// as one image), so it stands alone: the text title and
				// tagline would duplicate the wordmark baked into the
				// image. They render only with the built-in K mark — or
				// next to the logo when "Show Title Text" is on (see
				// Logo::brand, which appends the text brand itself).
				if ( \KoperasiKita\Features\Logo::hasLogo() ) {
					echo \KoperasiKita\Features\Logo::brand(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					?>
					<a class="kk-brand-mark kk-brand-mark--logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<img class="kk-brand-mark-logo" src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-k.svg' ); ?>" alt="" width="56" height="56" aria-hidden="true">
					</a>
					<div class="kk-brand-text">
						<p class="kk-site-title">
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php echo esc_html( \KoperasiKita\Features\Logo::titleText() ); ?></a>
						</p>
						<?php
						$description = \KoperasiKita\Features\Logo::subtitleText();
						if ( $description ) {
							?>
							<p class="kk-site-tagline"><?php echo esc_html( $description ); ?></p>
							<?php
						}
						?>
					</div>
					<?php
				}
				?>
			</div>
		</div>
	</div>
</header>

<?php
// The primary navigation must live outside <header>: .kk-nav-wrap is
// position: sticky, and a sticky element can only stick within its
// parent's box — inside the ~210px header it stuck for a few pixels
// and then scrolled away with the header. As a direct child of <body>
// it sticks to the top of the viewport for the whole page. The ticker
// moves out with it so the visual order (nav, then ticker) is kept.
get_template_part( 'template-parts/header/site-nav' );
?>

<?php get_template_part( 'template-parts/header/ticker' ); ?>

<?php
// Below Header widget area: full-width strip directly below the
// breaking-news ticker, above the main content. Shown on every page.
// Renders nothing when empty. NOT a sidebar, so it never gets a
// sticky setting.
if ( is_active_sidebar( 'kk-below-header' ) ) :
	?>
	<div class="kk-shell kk-widget-area kk-widget-area--below-header">
		<?php dynamic_sidebar( 'kk-below-header' ); ?>
	</div>
	<?php
endif;
?>

<?php
// The weather location picker lives here, not in the top bar: the top bar
// is toggleable (kk_topbar_enabled), and the picker is the dialog opened
// by the weather widget's "Change location" button as well as the top-bar
// button. If it lived in the top bar, disabling the top bar would leave
// the widget button dead (weather.ts silently skips binding when the
// dialog is absent). Rendered once per page, only when weather is on.
if ( \KoperasiKita\Features\Features::isEnabled( 'weather' ) ) {
	get_template_part( 'template-parts/weather/location-picker' );
}
?>

<main id="kk-main" class="kk-main">

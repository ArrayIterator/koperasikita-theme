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
<html <?php language_attributes(); ?> data-color-scheme="system">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="kk-skip-link" href="#kk-main"><?php esc_html_e( 'Skip to content', 'koperasikita' ); ?></a>

<header class="kk-site-header" id="kk-top">
	<?php get_template_part( 'template-parts/header/top-bar' ); ?>

	<div class="kk-masthead">
		<div class="kk-shell kk-masthead-inner">
			<div class="kk-brand">
				<?php
				if ( has_custom_logo() ) {
					the_custom_logo();
				} else {
					?>
					<a class="kk-brand-mark<?php echo get_theme_mod( 'kk_logo_invert_dark', false ) ? ' is-inverted' : ''; ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<span class="kk-brand-mark-letter" aria-hidden="true">K</span>
					</a>
					<?php
				}
				?>
				<div class="kk-brand-text">
					<p class="kk-site-title">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
					</p>
					<?php
					$description = get_bloginfo( 'description', 'display' );
					if ( $description ) {
						?>
						<p class="kk-site-tagline"><?php echo esc_html( $description ); ?></p>
						<?php
					}
					?>
				</div>
			</div>

			<p class="kk-masthead-date">
				<?php echo esc_html( date_i18n( get_option( 'date_format' ) ) ); ?>
			</p>
		</div>
	</div>

	<?php get_template_part( 'template-parts/header/site-nav' ); ?>

	<?php get_template_part( 'template-parts/header/ticker' ); ?>
</header>

<main id="kk-main" class="kk-main">

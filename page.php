<?php
/**
 * The page template.
 *
 * @package KoperasiKita
 */

get_header();
?>

<div class="kk-shell kk-page-layout">
	<div class="kk-content-area">
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content/breadcrumb' );
			$font_size = \KoperasiKita\Features\ArticleFontSize::currentSize();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'kk-page-article' ); ?> style="--article-font-size: <?php echo esc_attr( (string) $font_size ); ?>px">
				<header class="kk-page-header">
					<?php the_title( '<h1 class="kk-page-title">', '</h1>' ); ?>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="kk-page-featured">
						<?php the_post_thumbnail( 'large' ); ?>
					</figure>
				<?php endif; ?>

				<div class="kk-entry-content">
					<?php the_content(); ?>
				</div>
			</article>
			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
		endwhile;
		?>
	</div>

	<?php get_sidebar( 'page' ); ?>
</div>

<?php
get_footer();

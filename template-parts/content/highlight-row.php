<?php
/**
 * Highlights row: one compact row for the Slider Full slides and
 * the List columns — a small thumbnail (like a widget row), then
 * the title, with the category eyebrow and the date only when the
 * Highlights settings ask for them (Show Category / Show Date).
 *
 * The row is an <li> inside the List column <ul>, or the single
 * slide content inside a Slider Full slide. It is NOT a grid card:
 * no paper background, no shadow — the List and Slider Full
 * displays stay quiet so the section reads like a widget list.
 *
 * @package KoperasiKita
 */

$categories = get_the_category();
$thumb_url  = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'medium' );
$show_category = (bool) get_theme_mod( 'kk_highlights_show_category', true );
$show_date = (bool) get_theme_mod( 'kk_highlights_show_date', true );
?>

<li class="kk-highlight-row">
	<?php if ( $thumb_url ) : ?>
		<a class="kk-highlight-row-thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
		</a>
	<?php endif; ?>
	<div class="kk-highlight-row-body">
		<?php if ( $show_category && ! empty( $categories ) ) : ?>
			<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
		<?php endif; ?>
		<h3 class="kk-highlight-row-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<?php if ( $show_date ) : ?>
			<p class="kk-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</p>
		<?php endif; ?>
	</div>
</li>

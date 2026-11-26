<?php
/**
 * Category block row: one compact row for the List (Columns)
 * display — a small thumbnail (like a widget row), then the title
 * and the date. No category eyebrow: every post in the block is
 * already in this category, so the eyebrow would repeat the
 * section title on every row.
 *
 * The row is an <li> inside a List (Columns) column <ul>. It is
 * NOT a grid card: no paper background, no shadow — the List
 * display stays quiet so the section reads like a widget list.
 *
 * @package KoperasiKita
 */

$thumb_url = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'medium' );
?>

<li class="kk-category-row">
	<?php if ( $thumb_url ) : ?>
		<a class="kk-category-row-thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
		</a>
	<?php endif; ?>
	<div class="kk-category-row-body">
		<h3 class="kk-category-row-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p class="kk-meta">
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		</p>
	</div>
</li>

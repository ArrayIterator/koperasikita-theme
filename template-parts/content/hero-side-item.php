<?php
/**
 * Hero side item: one compact row in the Grid Center side columns.
 *
 * Grid Center is lead in the middle with a descending list on each
 * side (kiri menurun, kanan menurun): a small thumbnail, the
 * category eyebrow, the title and the date, stacked vertically so
 * the list reads top to bottom. Not a grid card — the sides stay
 * quiet so the lead in the middle carries the hero.
 *
 * @package KoperasiKita
 */

$categories = get_the_category();
$thumb_url  = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'medium' );
// The Hero card toggles (Customizer -> Homepage -> Show Title /
// Category / Date) apply to these side items too — they were
// rendering unconditionally, so toggling them did nothing
// ("ga fungsi") in Grid Center mode. Same defaults as card-news.php.
$show_title = (bool) get_theme_mod( 'kk_hero_card_show_title', true );
$show_category = (bool) get_theme_mod( 'kk_hero_card_show_category', true );
$show_date = (bool) get_theme_mod( 'kk_hero_card_show_date', true );
?>

<li class="kk-hero-side-item">
	<?php if ( $thumb_url ) : ?>
		<a class="kk-hero-side-thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
		</a>
	<?php endif; ?>
	<div class="kk-hero-side-body">
		<?php if ( $show_category && ! empty( $categories ) ) : ?>
			<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
		<?php endif; ?>
		<?php if ( $show_title ) : ?>
			<h3 class="kk-hero-side-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<?php endif; ?>
		<?php if ( $show_date ) : ?>
			<p class="kk-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</p>
		<?php endif; ?>
	</div>
</li>

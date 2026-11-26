<?php
/**
 * News card: thumbnail, category eyebrow, title, excerpt, meta, bookmark button.
 *
 * The bookmark is a direct child of the card (not inside the body or
 * the media): in grid layouts it shares the media grid area and sits
 * as a circle over the thumbnail; in list rows it takes its own
 * column at the right edge. That only works as a grid item, so a card
 * without a featured image falls back to the button in the body
 * instead. The two copies are identical on purpose; keep them in sync.
 *
 * Hero cards (the cards beside the lead, rendered with
 * $args['hero_card'] = true): the Hero Card settings decide which
 * parts show (title / category / date / excerpt) and where the
 * title sits (below the image, above the image, or inside the
 * featured image). Highlights cards (rendered with
 * $args['highlights_card'] = true) use the Highlights settings
 * the same way (category / date / excerpt; the title always
 * shows and stays below the image). Outside the hero and the
 * highlights the card keeps its default parts and the title
 * below the image.
 *
 * @package KoperasiKita
 */

$categories = get_the_category();
$thumb_url  = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'medium' );
$bookmarks_enabled = \KoperasiKita\Features\Features::isEnabled( 'bookmarks' );

$is_hero_card = ! empty( $args['hero_card'] );
$is_highlights_card = ! empty( $args['highlights_card'] );
$show_title = true;
$show_category = true;
$show_date = true;
$show_excerpt = true;
$title_position = 'below';
if ( $is_hero_card ) {
	$show_title = (bool) get_theme_mod( 'kk_hero_card_show_title', true );
	$show_category = (bool) get_theme_mod( 'kk_hero_card_show_category', true );
	$show_date = (bool) get_theme_mod( 'kk_hero_card_show_date', true );
	$show_excerpt = (bool) get_theme_mod( 'kk_hero_card_show_excerpt', false );
	$title_position = (string) get_theme_mod( 'kk_hero_card_title_position', 'below' );
	$title_position = in_array( $title_position, ['below', 'above', 'inside'], true ) ? $title_position : 'below';
} elseif ( $is_highlights_card ) {
	$show_category = (bool) get_theme_mod( 'kk_highlights_show_category', true );
	$show_date = (bool) get_theme_mod( 'kk_highlights_show_date', true );
	$show_excerpt = (bool) get_theme_mod( 'kk_highlights_show_excerpt', false );
}

$title_html = '';
if ( $show_title ) {
	$title_html = '<h3 class="kk-card-title"><a href="' . esc_url( get_the_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
}
?>

<article class="kk-card<?php echo $is_hero_card ? ' kk-card--hero kk-card--title-' . esc_attr( $title_position ) : ''; ?>">
	<?php if ( 'above' === $title_position && $is_hero_card ) : ?>
		<?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php endif; ?>

	<?php if ( $thumb_url ) : ?>
		<?php if ( 'inside' === $title_position && $is_hero_card ) : ?>
			<div class="kk-card-media-wrap">
				<a class="kk-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt=""<?php if ( \KoperasiKita\Images\FeaturedImage::isLazyEnabled() ) : ?> loading="lazy"<?php endif; ?>>
				</a>
				<?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php else : ?>
			<a class="kk-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
				<img src="<?php echo esc_url( $thumb_url ); ?>" alt=""<?php if ( \KoperasiKita\Images\FeaturedImage::isLazyEnabled() ) : ?> loading="lazy"<?php endif; ?>>
			</a>
		<?php endif; ?>
	<?php endif; ?>

	<div class="kk-card-body">
		<?php if ( $show_date ) : ?>
			<p class="kk-card-date"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() . ( get_theme_mod( 'kk_list_show_time', false ) ? ' - ' . get_the_time() : '' ) ); ?></time></p>
		<?php endif; ?>

		<?php if ( $show_category && ! empty( $categories ) ) : ?>
			<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
		<?php endif; ?>

		<?php if ( 'below' === $title_position ) : ?>
			<?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>

		<?php if ( $show_excerpt ) : ?>
			<p class="kk-card-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
		<?php endif; ?>

		<?php if ( (bool) get_theme_mod( 'kk_card_show_comments', false ) ) : ?>
			<p class="kk-meta">
				<a href="<?php the_permalink(); ?>#comments">
					<?php
					/* translators: %s: Number of comments. */
					printf( esc_html__( '%s comments', 'koperasikita' ), esc_html( number_format_i18n( get_comments_number() ) ) );
					?>
				</a>
			</p>
		<?php endif; ?>

		<?php if ( $bookmarks_enabled && ! $thumb_url ) : ?>
			<button
				type="button"
				class="kk-bookmark-button"
				data-kk-bookmark
				data-post-id="<?php echo esc_attr( get_the_ID() ); ?>"
				data-post-title="<?php echo esc_attr( get_the_title() ); ?>"
				data-post-url="<?php echo esc_url( get_the_permalink() ); ?>"
				data-post-excerpt="<?php echo esc_attr( get_the_excerpt() ); ?>"
				data-post-thumbnail="<?php echo esc_url( $thumb_url ); ?>"
				aria-pressed="false"
				aria-label="<?php esc_attr_e( 'Save bookmark', 'koperasikita' ); ?>"
			>
				<?php echo \KoperasiKita\Icons\Icon::svg( 'bookmark', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="kk-bookmark-label"><?php esc_html_e( 'Save', 'koperasikita' ); ?></span>
			</button>
		<?php endif; ?>
	</div>

	<?php if ( $bookmarks_enabled && $thumb_url ) : ?>
		<button
			type="button"
			class="kk-bookmark-button"
			data-kk-bookmark
			data-post-id="<?php echo esc_attr( get_the_ID() ); ?>"
			data-post-title="<?php echo esc_attr( get_the_title() ); ?>"
			data-post-url="<?php echo esc_url( get_the_permalink() ); ?>"
			data-post-excerpt="<?php echo esc_attr( get_the_excerpt() ); ?>"
			data-post-thumbnail="<?php echo esc_url( $thumb_url ); ?>"
			aria-pressed="false"
			aria-label="<?php esc_attr_e( 'Save bookmark', 'koperasikita' ); ?>"
		>
			<?php echo \KoperasiKita\Icons\Icon::svg( 'bookmark', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="kk-bookmark-label"><?php esc_html_e( 'Save', 'koperasikita' ); ?></span>
		</button>
	<?php endif; ?>
</article>

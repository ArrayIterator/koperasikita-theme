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
 * @package KoperasiKita
 */

$categories = get_the_category();
$thumb_url  = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'medium' );
$bookmarks_enabled = \KoperasiKita\Features\Features::isEnabled( 'bookmarks' );
?>

<article class="kk-card">
	<?php if ( $thumb_url ) : ?>
		<a class="kk-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt=""<?php if ( \KoperasiKita\Images\FeaturedImage::isLazyEnabled() ) : ?> loading="lazy"<?php endif; ?>>
		</a>
	<?php endif; ?>

	<div class="kk-card-body">
		<p class="kk-card-date"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() . ' - ' . get_the_time() ); ?></time></p>

		<?php if ( ! empty( $categories ) ) : ?>
			<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
		<?php endif; ?>

		<h3 class="kk-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p class="kk-card-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>

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

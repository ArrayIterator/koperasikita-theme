<?php
/**
 * Full single article: title, meta, featured image, content, tags and share.
 *
 * The author box lives in single.php so the Customizer can toggle it.
 *
 * @package KoperasiKita
 */

$thumb_url = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' );
$thumb_alt = get_post_meta( get_post_thumbnail_id(), '_wp_attachment_image_alt', true );
if ( ! $thumb_alt ) {
	$thumb_alt = get_the_title();
}
$font_size = \KoperasiKita\Features\ArticleFontSize::currentSize();
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'kk-article' ); ?> style="--article-font-size: <?php echo esc_attr( (string) $font_size ); ?>px">
	<header class="kk-article-header">
		<?php
		$categories = get_the_category();
		if ( ! empty( $categories ) ) {
			?>
			<p class="kk-eyebrow"><a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>"><?php echo esc_html( $categories[0]->name ); ?></a></p>
			<?php
		}
		?>
		<?php the_title( '<h1 class="kk-article-title">', '</h1>' ); ?>
		<p class="kk-article-meta">
			<span class="kk-article-author">
				<?php
				/* translators: %s: Author display name. */
				printf( esc_html__( 'By %s', 'koperasikita' ), esc_html( get_the_author() ) );
				?>
			</span>
			<span class="kk-meta-sep" aria-hidden="true">&middot;</span>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() . ( get_theme_mod( 'kk_single_show_time', false ) ? ' - ' . get_the_time() : '' ) ); ?></time>
			<?php if ( get_theme_mod( 'kk_single_reading_time', true ) ) : ?>
				<span class="kk-meta-sep" aria-hidden="true">&middot;</span>
				<span class="kk-reading-time">
					<?php
					$word_count = str_word_count( wp_strip_all_tags( get_the_content() ) );
					/* translators: %d: Estimated reading time in minutes. */
					printf( esc_html__( '%d min read', 'koperasikita' ), (int) max( 1, ceil( $word_count / 200 ) ) );
					?>
				</span>
			<?php endif; ?>
			<?php if ( \KoperasiKita\Features\Comments::shouldShow( get_the_ID() ) ) : ?>
			<span class="kk-meta-sep" aria-hidden="true">&middot;</span>
			<a href="#comments">
				<?php
				/* translators: %s: Number of comments. */
				printf( esc_html__( '%s comments', 'koperasikita' ), esc_html( number_format_i18n( get_comments_number() ) ) );
				?>
			</a>
			<?php endif; ?>
		</p>

		<?php if ( \KoperasiKita\Features\Features::isEnabled( 'bookmarks' ) ) : ?>
			<button
				type="button"
				class="kk-bookmark-button kk-bookmark-button-article"
				data-kk-bookmark
				data-post-id="<?php echo esc_attr( get_the_ID() ); ?>"
				data-post-title="<?php echo esc_attr( get_the_title() ); ?>"
				data-post-url="<?php echo esc_url( get_the_permalink() ); ?>"
				data-post-excerpt="<?php echo esc_attr( get_the_excerpt() ); ?>"
				data-post-thumbnail="<?php echo esc_url( $thumb_url ); ?>"
				data-label-bookmark="<?php echo esc_attr__( 'Bookmark', 'koperasikita' ); ?>"
				data-label-bookmarked="<?php echo esc_attr__( 'Bookmarked', 'koperasikita' ); ?>"
				aria-pressed="false"
				aria-label="<?php esc_attr_e( 'Save bookmark', 'koperasikita' ); ?>"
			>
				<?php echo \KoperasiKita\Icons\Icon::svg( 'bookmark', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="kk-bookmark-label"><?php esc_html_e( 'Bookmark', 'koperasikita' ); ?></span>
			</button>
		<?php endif; ?>
	</header>

	<?php if ( $thumb_url && get_theme_mod( 'kk_single_featured', true ) ) : ?>
		<figure class="kk-article-featured">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $thumb_alt ); ?>" loading="eager">
		</figure>
	<?php endif; ?>

	<div class="kk-entry-content">
		<?php the_content(); ?>
	</div>

	<?php
	// The footer renders when there are tags to show or share buttons
	// to offer; previously it was tied to tags alone, so an untagged
	// post never showed its share buttons.
	$tags = get_the_tags();
	$show_share = (bool) get_theme_mod( 'kk_single_show_share', true );
	if ( $tags || $show_share ) {
		?>
		<footer class="kk-article-footer">
			<?php if ( $tags ) : ?>
				<p class="kk-article-tags">
					<?php foreach ( $tags as $tag ) : ?>
						<a class="kk-tag" href="<?php echo esc_url( get_tag_link( $tag ) ); ?>"><?php echo esc_html( $tag->name ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( $show_share ) : ?>
				<div class="kk-share" aria-label="<?php esc_attr_e( 'Share this article', 'koperasikita' ); ?>">
					<span class="kk-share-label"><?php esc_html_e( 'Share', 'koperasikita' ); ?></span>
					<a class="kk-share-link" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( get_the_permalink() ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on Facebook', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'facebook', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<a class="kk-share-link" href="<?php echo esc_url( 'https://twitter.com/intent/tweet?url=' . rawurlencode( get_the_permalink() ) . '&text=' . rawurlencode( get_the_title() ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on X', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'x-social', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<a class="kk-share-link" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( get_the_title() . ' ' . get_the_permalink() ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Share on WhatsApp', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'whatsapp', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<a class="kk-share-link" href="<?php echo esc_url( 'mailto:?subject=' . rawurlencode( get_the_title() ) . '&body=' . rawurlencode( get_the_permalink() ) ); ?>" aria-label="<?php esc_attr_e( 'Share by email', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'mail', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<button type="button" class="kk-share-link kk-share-copy" data-share-url="<?php echo esc_url( get_the_permalink() ); ?>" data-copy-success="<?php esc_attr_e( 'Link copied', 'koperasikita' ); ?>" data-copy-error="<?php esc_attr_e( 'The link could not be copied.', 'koperasikita' ); ?>"><?php echo \KoperasiKita\Icons\Icon::svg( 'link', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="kk-share-copy-text"><?php esc_html_e( 'Copy link', 'koperasikita' ); ?></span></button>
					<span class="kk-share-status" aria-live="polite"></span>
				</div>
			<?php endif; ?>
		</footer>
		<?php
	}
	?>
</article>

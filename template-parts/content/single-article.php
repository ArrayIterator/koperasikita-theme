<?php
/**
 * Full single article: title, meta, featured image, content, tags and share.
 *
 * The author box lives in single.php so the Customizer can toggle it.
 *
 * @package KoperasiKita
 */

$thumb_url = \KoperasiKita\Images\FeaturedImage::url( get_the_ID(), 'large' );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'kk-article' ); ?>>
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
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
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
			<span class="kk-meta-sep" aria-hidden="true">&middot;</span>
			<a href="#comments">
				<?php
				/* translators: %s: Number of comments. */
				printf( esc_html__( '%s comments', 'koperasikita' ), esc_html( number_format_i18n( get_comments_number() ) ) );
				?>
			</a>
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
				aria-pressed="false"
				aria-label="<?php esc_attr_e( 'Save bookmark', 'koperasikita' ); ?>"
			>
				<?php echo \KoperasiKita\Icons\Icon::svg( 'bookmark', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="kk-bookmark-label"><?php esc_html_e( 'Save', 'koperasikita' ); ?></span>
			</button>
		<?php endif; ?>
	</header>

	<?php if ( $thumb_url && get_theme_mod( 'kk_single_featured', true ) ) : ?>
		<figure class="kk-article-featured">
			<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="eager">
		</figure>
	<?php endif; ?>

	<div class="kk-entry-content">
		<?php the_content(); ?>
	</div>

	<?php
	$tags = get_the_tags();
	if ( $tags ) {
		?>
		<footer class="kk-article-footer">
			<p class="kk-article-tags">
				<?php foreach ( $tags as $tag ) : ?>
					<a class="kk-tag" href="<?php echo esc_url( get_tag_link( $tag ) ); ?>"><?php echo esc_html( $tag->name ); ?></a>
				<?php endforeach; ?>
			</p>

			<div class="kk-share" aria-label="<?php esc_attr_e( 'Share this article', 'koperasikita' ); ?>">
				<span class="kk-share-label"><?php esc_html_e( 'Share', 'koperasikita' ); ?></span>
				<a class="kk-share-link" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( get_the_permalink() ) ); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
				<a class="kk-share-link" href="<?php echo esc_url( 'https://twitter.com/intent/tweet?url=' . rawurlencode( get_the_permalink() ) . '&text=' . rawurlencode( get_the_title() ) ); ?>" target="_blank" rel="noopener noreferrer">X</a>
				<a class="kk-share-link" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( get_the_title() . ' ' . get_the_permalink() ) ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
				<a class="kk-share-link" href="<?php echo esc_url( 'mailto:?subject=' . rawurlencode( get_the_title() ) . '&body=' . rawurlencode( get_the_permalink() ) ); ?>"><?php esc_html_e( 'Email', 'koperasikita' ); ?></a>
			</div>
		</footer>
		<?php
	}
	?>
</article>

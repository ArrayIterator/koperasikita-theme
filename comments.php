<?php
/**
 * The comments template.
 *
 * Rendered only when comments are open or comments exist (see single.php / page.php).
 *
 * @package KoperasiKita
 */

if ( post_password_required() ) {
	return;
}

$kk_show_avatars = (bool) get_theme_mod( 'kk_comments_avatars', true );
$kk_newest_first = get_theme_mod( 'kk_comments_order', 'oldest' ) === 'newest';
?>

<section id="comments" class="kk-comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="kk-comments-title">
			<?php
			$comments_number = get_comments_number();
			if ( 1 === (int) $comments_number ) {
				esc_html_e( 'One comment', 'koperasikita' );
			} else {
				/* translators: %s: Number of comments. */
				printf( esc_html__( '%s comments', 'koperasikita' ), esc_html( number_format_i18n( $comments_number ) ) );
			}
			?>
		</h2>

		<ol class="kk-comment-list">
			<?php
			wp_list_comments(
				array(
					'style'             => 'ol',
					'short_ping'        => true,
					'avatar_size'       => $kk_show_avatars ? 48 : 0,
					'reverse_top_level' => $kk_newest_first,
				)
			);
			?>
		</ol>

		<?php
		the_comments_navigation(
			array(
				'prev_text' => __( '&larr; Older comments', 'koperasikita' ),
				'next_text' => __( 'Newer comments &rarr;', 'koperasikita' ),
			)
		);
		?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="kk-comments-closed"><?php esc_html_e( 'Comments are closed.', 'koperasikita' ); ?></p>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'title_reply'         => __( 'Leave a comment', 'koperasikita' ),
			'title_reply_before'  => '<h3 id="reply-title" class="kk-comment-form-title">',
			'title_reply_after'   => '</h3>',
			'comment_notes_before' => '',
			'class_submit'        => 'kk-button',
			'submit_button'       => '<button type="submit" name="%1$s" id="%2$s" class="%3$s">%4$s</button>',
		)
	);
	?>
</section>

<?php
/**
 * Editor picks: numbered editorial list (1-4).
 *
 * Accepts $args['offset'] (default 1, so picks start after the hero lead).
 *
 * @package KoperasiKita
 */

$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 1;

$picks = new WP_Query(
	array(
		'post_type'           => 'post',
		'posts_per_page'      => 4,
		'offset'              => $offset,
		'ignore_sticky_posts' => true,
	)
);

if ( ! $picks->have_posts() ) {
	return;
}
?>

<section class="kk-editor-picks" aria-labelledby="kk-picks-heading">
	<h2 class="kk-section-title" id="kk-picks-heading"><?php esc_html_e( 'Editor’s Picks', 'koperasikita' ); ?></h2>
	<ol class="kk-picks-list">
		<?php
		$number = 1;
		while ( $picks->have_posts() ) :
			$picks->the_post();
			?>
			<li class="kk-pick">
				<span class="kk-pick-number" aria-hidden="true"><?php echo esc_html( $number ); ?></span>
				<div class="kk-pick-body">
					<h3 class="kk-pick-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
					<p class="kk-meta">
						<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					</p>
				</div>
			</li>
			<?php
			$number++;
		endwhile;
		?>
	</ol>
</section>

<?php
wp_reset_postdata();

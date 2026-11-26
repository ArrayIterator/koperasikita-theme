<?php
/**
 * News ticker: content width, bouncing fade rotation.
 *
 * @package KoperasiKita
 */

if ( ! \KoperasiKita\Features\Features::isEnabled( 'ticker' ) ) {
	return;
}

$ticker_posts = \KoperasiKita\Features\Ticker::posts();

if ( empty( $ticker_posts ) ) {
	return;
}

$ticker_speed = \KoperasiKita\Features\Ticker::speed();
$ticker_title = (string) get_theme_mod( 'kk_breaking_title', __( 'Breaking', 'koperasikita' ) );
$ticker_title = '' !== trim( $ticker_title ) ? $ticker_title : __( 'Breaking', 'koperasikita' );
$ticker_style = (string) get_theme_mod( 'kk_breaking_style', 'gold' );
$ticker_style = in_array( $ticker_style, ['gold', 'green', 'dark', 'red', 'outline'], true ) ? $ticker_style : 'gold';
?>

<div class="kk-ticker-wrap">
	<div class="kk-shell">
		<div class="kk-ticker" role="region" aria-label="<?php esc_attr_e( 'Breaking news', 'koperasikita' ); ?>">
			<span class="kk-ticker-label kk-ticker-label--<?php echo esc_attr( $ticker_style ); ?>"><?php echo esc_html( $ticker_title ); ?></span>
			<ul class="kk-ticker-list" id="kk-ticker-list" data-speed="<?php echo esc_attr( (string) $ticker_speed ); ?>">
				<?php foreach ( $ticker_posts as $index => $ticker_post ) : ?>
					<li class="kk-ticker-item<?php echo 0 === $index ? ' is-active' : ''; ?>">
						<a href="<?php echo esc_url( get_permalink( $ticker_post ) ); ?>">
							<?php echo esc_html( get_the_title( $ticker_post ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="kk-ticker-controls">
				<button type="button" class="kk-icon-button kk-ticker-prev" id="kk-ticker-prev" aria-label="<?php esc_attr_e( 'Previous headline', 'koperasikita' ); ?>">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'prev', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<button type="button" class="kk-icon-button kk-ticker-toggle" id="kk-ticker-toggle" aria-label="<?php esc_attr_e( 'Pause headlines', 'koperasikita' ); ?>" aria-pressed="false">
					<span class="kk-icon-pause"><?php echo \KoperasiKita\Icons\Icon::svg( 'pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="kk-icon-play"><?php echo \KoperasiKita\Icons\Icon::svg( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</button>
				<button type="button" class="kk-icon-button kk-ticker-next" id="kk-ticker-next" aria-label="<?php esc_attr_e( 'Next headline', 'koperasikita' ); ?>">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'next', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</div>
	</div>
</div>

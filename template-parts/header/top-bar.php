<?php
/**
 * Top bar: date, weather slot, dark-mode toggle, search button.
 *
 * @package KoperasiKita
 */

?>

<div class="kk-topbar">
	<div class="kk-shell kk-topbar-inner">
		<p class="kk-topbar-date"><?php echo esc_html( date_i18n( 'l, j F Y' ) ); ?></p>

		<div class="kk-topbar-right">
			<?php if ( \KoperasiKita\Features\Features::isEnabled( 'weather' ) ) : ?>
				<div id="kk-topbar-weather" class="kk-topbar-weather" aria-live="polite"></div>
				<button type="button" class="kk-icon-button kk-weather-open" data-kk-weather-open aria-label="<?php esc_attr_e( 'Change weather location', 'koperasikita' ); ?>" aria-haspopup="dialog" aria-controls="kk-weather-picker">
					<?php echo \KoperasiKita\Icons\Icon::svg( 'location', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<?php get_template_part( 'template-parts/weather/location-picker' ); ?>
			<?php endif; ?>

			<button type="button" class="kk-icon-button kk-color-scheme-toggle" id="kk-color-scheme-toggle" aria-label="<?php esc_attr_e( 'Toggle color scheme', 'koperasikita' ); ?>" aria-pressed="false">
				<span class="kk-icon-sun"><?php echo \KoperasiKita\Icons\Icon::svg( 'sun', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="kk-icon-moon"><?php echo \KoperasiKita\Icons\Icon::svg( 'moon', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>

			<button type="button" class="kk-icon-button kk-search-open" id="kk-search-open" aria-label="<?php esc_attr_e( 'Search', 'koperasikita' ); ?>" aria-haspopup="dialog" aria-controls="kk-search-overlay">
				<?php echo \KoperasiKita\Icons\Icon::svg( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="kk-search-open-label"><?php esc_html_e( 'Search', 'koperasikita' ); ?></span>
			</button>
		</div>
	</div>
</div>

<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Assets\ViteLoader;
use WP_Customize_Control;

/**
 * A searchable single-select term picker for the Customizer
 * (Select2-style, no jQuery).
 *
 * Renders the control's `choices` (value => label) as a dropdown with a
 * search field on top: typing filters the list, clicking (or Enter)
 * picks one. The selection syncs into a hidden input (linked to the
 * setting) through `src/ts/customizer-controls.ts`, so the Customizer
 * picks the value up as one setting value. The value is a single term
 * ID (or 0 for the "Automatic" choice); the setting's sanitize
 * callback validates it, so a stale or forged value never survives.
 *
 * The dropdown is keyboard-friendly: ArrowDown/ArrowUp move the
 * highlight, Enter selects, Escape closes. The panel closes on an
 * outside click. The control degrades gracefully without JS: the
 * hidden input still holds the saved value and the trigger shows it.
 */
final class TermSelectControl extends WP_Customize_Control
{
    public function enqueue(): void
    {
        $js = ViteLoader::js('src/ts/customizer-controls.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-controls', $js, ['customize-controls'], KK_VERSION, true);
        }

        $css = ViteLoader::css('src/css/customizer-controls.css');

        if ($css !== '') {
            wp_enqueue_style('kk-customizer-controls', $css, [], KK_VERSION);
        }
    }

    public function render_content(): void
    {
        $choices = $this->choices;
        $current = (string) $this->value();
        $current_label = $choices[$current] ?? $choices['0'] ?? reset($choices);

        if (!is_string($current_label)) {
            $current_label = (string) $current_label;
        }
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <div class="kk-term-select" data-kk-term-select>
            <button type="button" class="kk-term-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                <span class="kk-term-select-value"><?php echo esc_html($current_label); ?></span>
                <span class="kk-term-select-chevron" aria-hidden="true"></span>
            </button>
            <div class="kk-term-select-panel" hidden>
                <input type="search" class="kk-term-select-search" placeholder="<?php esc_attr_e('Search…', 'koperasikita'); ?>" aria-label="<?php esc_attr_e('Search terms', 'koperasikita'); ?>" autocomplete="off">
                <ul class="kk-term-select-list" role="listbox">
                    <?php foreach ($choices as $value => $label) : ?>
                        <li class="kk-term-select-option<?php echo (string) $value === $current ? ' is-selected' : ''; ?>" role="option" aria-selected="<?php echo (string) $value === $current ? 'true' : 'false'; ?>" data-value="<?php echo esc_attr((string) $value); ?>">
                            <?php echo esc_html((string) $label); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="kk-term-select-empty" hidden><?php esc_html_e('No terms match your search.', 'koperasikita'); ?></p>
            </div>
            <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr($current); ?>">
        </div>
        <?php
    }
}

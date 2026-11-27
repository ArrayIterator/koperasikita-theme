<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Assets\ViteLoader;
use WP_Customize_Control;

/**
 * A searchable single-select term picker for the Customizer
 * (Select2-style, AJAX mode, no jQuery).
 *
 * The option list is NEVER rendered into the page. Instead the
 * dropdown asks `TermSearch::ajaxSearchTerms` for the matches as the
 * editor types (debounced), so a site with hundreds of categories or
 * tags stays light on both sides: one small query per keystroke,
 * capped at 20 rows, and only the matches are ever in the DOM.
 * Static choices (e.g. value 0 = "Automatic (most posts)") are the
 * only options rendered server-side - set them through the control
 * args as `'static_choices' => [0 => 'Automatic (most posts)']` - and
 * the taxonomy through `'taxonomy' => 'category'`.
 *
 * The trigger label is resolved server-side with a single term
 * lookup, so the saved value always shows its name even though the
 * list itself loads on demand. The selection syncs into a hidden
 * input (linked to the setting) through
 * `src/ts/customizer-controls.ts`, so the Customizer picks the value
 * up as one setting value. The value is a single term ID (or 0 for
 * the static choice); the setting's sanitize callback validates it,
 * so a stale or forged value never survives.
 *
 * The dropdown is keyboard-friendly: ArrowDown/ArrowUp move the
 * highlight, Enter selects, Escape closes. The panel closes on an
 * outside click. The control degrades gracefully without JS: the
 * hidden input still holds the saved value and the trigger shows it.
 */
final class TermSelectControl extends WP_Customize_Control
{
    public string $taxonomy = 'category';

    /** @var array<int|string, string> */
    public array $static_choices = [];

    public function enqueue(): void
    {
        $js = ViteLoader::js('src/ts/customizer-controls.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-controls', $js, ['customize-controls'], KK_VERSION, true);
            wp_localize_script('kk-customizer-controls', 'kkCustomizerControls', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => Ajax::nonce(),
            ]);
        }

        $css = ViteLoader::css('src/css/customizer-controls.css');

        if ($css !== '') {
            wp_enqueue_style('kk-customizer-controls', $css, [], KK_VERSION);
        }
    }

    private function currentLabel(): string
    {
        $current = (string) $this->value();

        if (isset($this->static_choices[$current])) {
            return (string) $this->static_choices[$current];
        }

        if (isset($this->static_choices['0']) && $current === '') {
            return (string) $this->static_choices['0'];
        }

        $id = (int) $current;

        if ($id > 0) {
            $term = get_term($id, $this->taxonomy);

            if ($term instanceof \WP_Term) {
                return $term->name . ' (' . (int) $term->count . ')';
            }
        }

        return (string) ($this->static_choices['0'] ?? $this->static_choices[0] ?? '');
    }

    public function render_content(): void
    {
        $current = (string) $this->value();
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <div class="kk-term-select" data-kk-term-select data-taxonomy="<?php echo esc_attr($this->taxonomy); ?>" data-static="<?php echo esc_attr((string) wp_json_encode($this->static_choices)); ?>">
            <button type="button" class="kk-term-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                <span class="kk-term-select-value"><?php echo esc_html($this->currentLabel()); ?></span>
                <span class="kk-term-select-chevron" aria-hidden="true"></span>
            </button>
            <div class="kk-term-select-panel" hidden>
                <input type="search" class="kk-term-select-search" placeholder="<?php esc_attr_e('Search…', 'koperasikita'); ?>" aria-label="<?php esc_attr_e('Search terms', 'koperasikita'); ?>" autocomplete="off">
                <ul class="kk-term-select-list" role="listbox"></ul>
                <p class="kk-term-select-empty" hidden><?php esc_html_e('No terms match your search.', 'koperasikita'); ?></p>
                <p class="kk-term-select-loading" hidden><?php esc_html_e('Loading…', 'koperasikita'); ?></p>
            </div>
            <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr($current); ?>">
        </div>
        <?php
    }
}

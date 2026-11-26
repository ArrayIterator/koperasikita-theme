<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Assets\ViteLoader;
use WP_Customize_Control;
use WP_Error;

/**
 * A searchable multi-select term picker for the Customizer
 * (Select2-style, AJAX mode, no jQuery).
 *
 * Like TermSelectControl, the option list is NEVER rendered into the
 * page: the dropdown asks `TermSearch::ajaxSearchTerms` for the
 * matches as the editor types (debounced, capped at 20 rows), so a
 * site with hundreds of categories or tags stays light on both the
 * server and the client. The taxonomy comes through the control args
 * as `'taxonomy' => 'category'`.
 *
 * The selection is a set of chips (one per picked term) plus a hidden
 * input holding a JSON array of term IDs, synced through
 * `src/ts/customizer-controls.ts`. The setting's sanitize callback
 * (`sanitizeTermIds`) accepts that JSON string as well as a plain
 * array, so the value survives both the Customizer save and a direct
 * set_theme_mod(). The picked terms' labels are resolved server-side
 * with a single `get_terms( include => ... )` call, so the chips
 * always show their names even though the list loads on demand.
 */
final class TermMultiSelectControl extends WP_Customize_Control
{
    public string $taxonomy = 'category';

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

    /**
     * @return array<int>
     */
    private function selectedIds(): array
    {
        $value = $this->value();

        if (is_array($value)) {
            $ids = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $ids = is_array($decoded) ? $decoded : explode(',', $value);
        } else {
            $ids = [];
        }

        $ids = array_map('absint', (array) $ids);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    public function render_content(): void
    {
        $selected = $this->selectedIds();
        $terms = [];

        if ($selected !== []) {
            $found = get_terms([
                'taxonomy' => $this->taxonomy,
                'hide_empty' => false,
                'include' => $selected,
                'orderby' => 'include',
            ]);

            if (!($found instanceof WP_Error)) {
                $terms = $found;
            }
        }
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <div class="kk-term-multiselect" data-kk-term-multiselect data-taxonomy="<?php echo esc_attr($this->taxonomy); ?>">
            <div class="kk-term-multiselect-box" tabindex="0" role="combobox" aria-expanded="false" aria-label="<?php esc_attr_e('Search terms', 'koperasikita'); ?>">
                <span class="kk-term-multiselect-chips">
                    <?php foreach ($terms as $term) : ?>
                        <span class="kk-term-multiselect-chip" data-value="<?php echo esc_attr((string) $term->term_id); ?>">
                            <?php echo esc_html($term->name); ?>
                            <button type="button" class="kk-term-multiselect-remove" aria-label="<?php esc_attr_e('Remove', 'koperasikita'); ?>">&times;</button>
                        </span>
                    <?php endforeach; ?>
                </span>
                <input type="search" class="kk-term-multiselect-search" placeholder="<?php echo $selected === [] ? esc_attr__('Search terms…', 'koperasikita') : esc_attr__('Add…', 'koperasikita'); ?>" aria-label="<?php esc_attr_e('Search terms', 'koperasikita'); ?>" autocomplete="off">
            </div>
            <div class="kk-term-multiselect-panel" hidden>
                <ul class="kk-term-multiselect-list" role="listbox"></ul>
                <p class="kk-term-multiselect-empty" hidden><?php esc_html_e('No terms match your search.', 'koperasikita'); ?></p>
                <p class="kk-term-multiselect-loading" hidden><?php esc_html_e('Loading…', 'koperasikita'); ?></p>
            </div>
            <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr((string) wp_json_encode($selected)); ?>">
        </div>
        <?php
    }
}

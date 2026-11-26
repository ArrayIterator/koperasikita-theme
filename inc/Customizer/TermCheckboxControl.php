<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Assets\ViteLoader;
use WP_Customize_Control;
use WP_Error;

/**
 * A multi-select term picker for the Customizer.
 *
 * Renders the terms of one taxonomy as a scrollable checkbox list and
 * stores the selection as an array of term IDs. The checkboxes sync
 * into a hidden input (linked to the setting) as a JSON array through
 * `src/ts/customizer-controls.ts`; the setting's sanitize callback
 * accepts that JSON string as well as a plain array, so the value
 * survives both the Customizer save and a direct set_theme_mod().
 *
 * Set the taxonomy through the control args: `'taxonomy' => 'category'`.
 */
final class TermCheckboxControl extends WP_Customize_Control
{
    public string $taxonomy = 'category';

    public function enqueue(): void
    {
        $js = ViteLoader::js('src/ts/customizer-controls.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-controls', $js, ['customize-controls'], KK_VERSION, true);
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
        $terms = get_terms([
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <?php if ($terms instanceof WP_Error || $terms === []) : ?>
            <p><?php esc_html_e('No terms found.', 'koperasikita'); ?></p>
        <?php else : ?>
            <?php $selected = $this->selectedIds(); ?>
            <input type="search" class="kk-term-checkbox-filter" placeholder="<?php esc_attr_e('Search terms…', 'koperasikita'); ?>" aria-label="<?php esc_attr_e('Search terms', 'koperasikita'); ?>" autocomplete="off">
            <div data-kk-term-checkboxes style="max-height: 180px; overflow-y: auto; border: 1px solid #dcdcde; background: #fff; padding: 6px 8px;">
                <?php foreach ($terms as $term) : ?>
                    <label style="display: block; padding: 3px 0; font-weight: 400;">
                        <input type="checkbox" value="<?php echo esc_attr((string) $term->term_id); ?>"<?php checked(in_array($term->term_id, $selected, true)); ?>>
                        <?php echo esc_html($term->name); ?>
                        <span style="color: #646970;">(<?php echo esc_html((string) $term->count); ?>)</span>
                    </label>
                <?php endforeach; ?>
                <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr((string) wp_json_encode($selected)); ?>">
            </div>
        <?php endif; ?>
        <?php
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use WP_Term;
use WP_Widget;

/**
 * Real Select2 term picker for classic widget forms.
 *
 * Renders a `<select>` that admin.ts turns into a Select2 with
 * AJAX search (see select2-terms.ts): the dropdown stays closed
 * until the field is focused or clicked, typing searches the
 * taxonomy, and the underlying select stays in sync so the widget
 * form submits the picked term IDs. Only the already-picked terms
 * are rendered as options, so the form never pre-loads every term
 * in the database - a site with hundreds of categories stays
 * light. Without JS the select is still a plain multi-select (or
 * a plain dropdown for one term), so the form keeps working.
 */
final class Select2TermField
{
    /**
     * Render one term picker.
     *
     * @param WP_Widget $widget The widget rendering its form.
     * @param string $key Instance key (stores term ids).
     * @param string $label Field label.
     * @param string $taxonomy Taxonomy to search.
     * @param int[] $selectedIds Already-picked term ids.
     * @param bool $multiple True for a multi-select, false for one term.
     * @param string $placeholder Placeholder shown when nothing is picked.
     */
    public static function render(
        WP_Widget $widget,
        string $key,
        string $label,
        string $taxonomy,
        array $selectedIds,
        bool $multiple,
        string $placeholder
    ): void {
        $fieldId = $widget->get_field_id($key);
        $fieldName = $widget->get_field_name($key);
        $ids = [];
        foreach ($selectedIds as $termId) {
            $termId = (int) $termId;
            if ($termId > 0 && !in_array($termId, $ids, true)) {
                $ids[] = $termId;
            }
        }
        ?>
        <p>
            <label for="<?php echo esc_attr($fieldId); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <select
                class="widefat kk-select2-terms"
                data-kk-select2-terms
                data-taxonomy="<?php echo esc_attr($taxonomy); ?>"
                data-placeholder="<?php echo esc_attr($placeholder); ?>"
                id="<?php echo esc_attr($fieldId); ?>"
                name="<?php echo esc_attr($fieldName . ($multiple ? '[]' : '')); ?>"
                <?php if ($multiple) : ?>multiple<?php endif; ?>
            >
                <?php if (!$multiple) : ?>
                    <option value=""></option>
                <?php endif; ?>
                <?php foreach ($ids as $termId) : ?>
                    <?php $term = get_term($termId, $taxonomy); ?>
                    <?php if ($term instanceof WP_Term) : ?>
                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" selected="selected">
                            <?php echo esc_html($term->name); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }
}

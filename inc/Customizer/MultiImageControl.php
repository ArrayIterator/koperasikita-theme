<?php

declare(strict_types=1);

namespace KoperasiKita\Customizer;

use KoperasiKita\Assets\ViteLoader;
use WP_Customize_Control;

/**
 * A multi-image picker for the Customizer (dynamic list, no jQuery).
 *
 * Renders the picked images as a row of thumbnails, each with a
 * remove button, plus an "Add images" button that opens the media
 * library (multiple selection). The selection syncs into a hidden
 * input holding a JSON array of attachment IDs, so the Customizer
 * picks the value up as one setting value. The setting's sanitize
 * callback (`sanitizeAttachmentIds`) accepts that JSON string as well
 * as a plain array or a single legacy attachment ID, so an old
 * single-image value migrates instead of breaking.
 *
 * Used for the PWA install screenshots (Wide + Narrow): the manifest
 * lists every picked image, so the install prompt can show more than
 * one screenshot per form factor.
 *
 * The media frame is wired through `src/ts/customizer-controls.ts`
 * (`initMultiImage`), which uses the `wp.media` frame the Customizer
 * already loads for the media controls.
 */
final class MultiImageControl extends WP_Customize_Control
{
    public function enqueue(): void
    {
        // The media library frame. The PWA section also has a
        // WP_Customize_Media_Control (the app icon), which loads it
        // too; enqueueing here keeps this control working on its own.
        wp_enqueue_media();

        $js = ViteLoader::js('src/ts/customizer-controls.ts');

        if ($js !== '') {
            wp_enqueue_script('kk-customizer-controls', $js, ['customize-controls'], KK_VERSION, true);
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
            $ids = is_array($decoded) ? $decoded : [$value];
        } elseif (is_int($value) || is_numeric($value)) {
            $ids = [(int) $value];
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
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ($this->description !== '') : ?>
            <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
        <?php endif; ?>
        <div class="kk-multi-image" data-kk-multi-image>
            <div class="kk-multi-image-list">
                <?php foreach ($selected as $id) : ?>
                    <?php
                    $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                    $full = wp_get_attachment_image_src($id, 'full');
                    if ($thumb === false || $full === false) {
                        continue;
                    }
                    ?>
                    <span class="kk-multi-image-item" data-value="<?php echo esc_attr((string) $id); ?>">
                        <img src="<?php echo esc_url($thumb[0]); ?>" alt="" width="64" height="64">
                        <button type="button" class="kk-multi-image-remove" aria-label="<?php esc_attr_e('Remove image', 'koperasikita'); ?>">&times;</button>
                    </span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button kk-multi-image-add"><?php esc_html_e('Add images', 'koperasikita'); ?></button>
            <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr((string) wp_json_encode($selected)); ?>">
        </div>
        <?php
    }
}

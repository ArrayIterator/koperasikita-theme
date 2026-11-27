<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Gold Data Desk widget: the gold brands as a data table - one row
 * per brand with the price per gram and the last-updated date.
 *
 * The widget renders a Data Desk template of kind "table" created
 * on the Data Desk Templates admin page. It carries no settings of
 * its own - the template picks the brands (empty = all enabled
 * brands) and the palette. The header title is the widget title, or
 * the template title when the widget title is empty.
 *
 * Prices come from the Markets snapshot layer (Settings -> Markets).
 * The rows carry the same data-kk-symbol / data-kk-price hooks as
 * the Markets strip, so the gold-markets script refreshes the
 * prices live. When the Markets feature is disabled the widget
 * renders nothing, and when no brand has a saved price it renders
 * an honest empty state instead of invented numbers.
 */
final class GoldDataDeskWidget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'kk_gold_data_desk',
            __('Koperasi Kita Gold Data Desk', 'koperasikita'),
            [
                'description' => __('Gold prices for Indonesian gold brands as a data table. Pick a template.', 'koperasikita'),
                'classname' => 'kk-gold-data-desk-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        if (!Markets::isEnabled()) {
            return;
        }

        $templateId = isset($instance['template']) ? sanitize_key((string) $instance['template']) : '';
        $template = $templateId !== '' ? DataDesk::getTemplate($templateId) : null;
        // A panel template picked here (its kind was changed after the
        // widget picked it) falls back to the first stored table
        // template - this widget only ever renders tables. With no
        // table template at all the widget renders nothing.
        if ($template === null || $template['kind'] !== DataDesk::KIND_TABLE) {
            $template = DataDesk::firstTemplateOfKind(DataDesk::KIND_TABLE);
        }

        if ($template === null) {
            return;
        }

        $brands = $template['brands'] !== []
            ? array_values(array_intersect($template['brands'], Gold::enabledBrands()))
            : Gold::enabledBrands();
        $prices = Gold::brandPrices($brands);

        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $title = $title !== '' ? $title : $template['title'];

        echo WidgetHeader::beforeWidget($args, WidgetHeader::style($instance));

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        get_template_part('template-parts/gold/data-desk', null, [
            'brands' => $prices,
            'client' => Markets::clientInfo('gold'),
            'palette' => $template['palette'],
            'headers' => $template['headers'] ?? [],
        ]);

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $templateId = isset($instance['template']) ? sanitize_key((string) $instance['template']) : '';
        // The preview shows what the widget will render: a panel
        // template picked here falls back to the first stored table,
        // the same fallback widget() applies. With no table template
        // the preview says so instead of showing a built-in.
        $previewTemplate = $templateId !== '' ? DataDesk::getTemplate($templateId) : null;
        if ($previewTemplate === null || $previewTemplate['kind'] !== DataDesk::KIND_TABLE) {
            $previewTemplate = DataDesk::firstTemplateOfKind(DataDesk::KIND_TABLE);
        }
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                value="<?php echo esc_attr($title); ?>"
            >
            <span class="description">
                <?php esc_html_e('Leave empty to use the template title.', 'koperasikita'); ?>
            </span>
        </p>
        <?php WidgetHeader::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('template')); ?>">
                <?php esc_html_e('Template', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                data-kk-data-desk-template-select
                id="<?php echo esc_attr($this->get_field_id('template')); ?>"
                name="<?php echo esc_attr($this->get_field_name('template')); ?>"
            >
                <?php foreach (DataDesk::templatesOfKind(DataDesk::KIND_TABLE) as $id => $template) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($templateId, $id); ?>>
                        <?php echo esc_html($template['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">
                <?php esc_html_e('Manage templates on the Data Desk Templates page.', 'koperasikita'); ?>
            </span>
        </p>
        <?php /* Layout preview: the real table markup with sample data, re-rendered by admin.ts
         * (kk_data_desk_preview) when the template above changes. The block editor needs no
         * extra box - its ServerSideRender preview below the settings is the live preview. */ ?>
        <div class="kk-data-desk-widget-preview" data-kk-data-desk-widget-preview data-kk-kind="table">
            <p class="kk-data-desk-widget-preview-label"><?php esc_html_e('Layout preview — sample data', 'koperasikita'); ?></p>
            <div data-kk-data-desk-widget-preview-body>
                <?php if ($previewTemplate !== null) : ?>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                    echo DataDesk::renderPreview($previewTemplate);
                    ?>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('No templates yet. Create one on the Data Desk Templates page.', 'koperasikita'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $new New settings.
     * @param array<string, mixed> $old Previous settings.
     * @return array<string, mixed>
     */
    public function update($new, $old): array
    {
        $instance = [];
        $instance['title'] = isset($new['title']) ? sanitize_text_field((string) $new['title']) : '';

        $templateId = isset($new['template']) ? sanitize_key((string) $new['template']) : '';
        $templates = DataDesk::templatesOfKind(DataDesk::KIND_TABLE);
        $first = DataDesk::firstTemplateOfKind(DataDesk::KIND_TABLE);
        $instance['template'] = isset($templates[$templateId]) ? $templateId : ($first['id'] ?? '');

        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);

        return $instance;
    }
}

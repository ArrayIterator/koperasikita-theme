<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Markets;
use WP_Widget;

/**
 * Data Desk widget: one market symbol as a panel - a line chart of
 * the last N days on the left, three stats on the right (price with
 * day change, buyback with spread, weekly performance).
 *
 * The widget renders a Data Desk template created on the Data
 * Desk Templates admin page. The chart is an inline SVG generated
 * server-side from the stored price history (gold) or a
 * placeholder (stocks, which have no history table yet). When the
 * Markets feature is disabled the widget renders nothing.
 */
final class DataDeskWidget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'kk_data_desk',
            __('Koperasi Kita Data Desk', 'koperasikita'),
            [
                'description' => __('One market symbol as a data desk panel: price chart and stats. Pick a template.', 'koperasikita'),
                'classname' => 'kk-data-desk-widget',
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
        // A table template picked here (its kind was changed after the
        // widget picked it) falls back to the first stored panel
        // template - this widget only ever renders panels. With no
        // panel template at all the widget renders nothing.
        if ($template === null || $template['kind'] !== DataDesk::KIND_PANEL) {
            $template = DataDesk::firstTemplateOfKind(DataDesk::KIND_PANEL);
        }

        if ($template === null) {
            return;
        }

        $parsed = DataDesk::parseSymbol($template['symbol']);
        $parsed = $parsed ?? ['market' => 'gold', 'symbol' => 'antam'];

        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $data = DataDesk::panelData($parsed['market'], $parsed['symbol'], (int) $template['days']);

        echo WidgetHeader::beforeWidget($args, WidgetHeader::style($instance));

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        get_template_part('template-parts/data-desk/panel', null, [
            'data' => $data,
            'palette' => $template['palette'],
            'days' => (int) $template['days'],
            'title' => $template['title'],
            'kicker' => $template['kicker'] ?? '',
            'date_line' => $template['date_line'] ?? '',
            'stats' => $template['stats'] ?? [],
            'hidden_blocks' => $template['hidden_blocks'] ?? [],
            'placeholders' => $template['placeholders'] ?? [],
        ]);

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $templateId = isset($instance['template']) ? sanitize_key((string) $instance['template']) : '';
        // The preview shows what the widget will render: a table
        // template picked here falls back to the first stored panel,
        // the same fallback widget() applies. With no panel template
        // the preview says so instead of showing a built-in.
        $previewTemplate = $templateId !== '' ? DataDesk::getTemplate($templateId) : null;
        if ($previewTemplate === null || $previewTemplate['kind'] !== DataDesk::KIND_PANEL) {
            $previewTemplate = DataDesk::firstTemplateOfKind(DataDesk::KIND_PANEL);
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
                <?php foreach (DataDesk::templatesOfKind(DataDesk::KIND_PANEL) as $id => $template) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($templateId, $id); ?>>
                        <?php echo esc_html($template['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">
                <?php esc_html_e('Manage templates on the Data Desk Templates page.', 'koperasikita'); ?>
            </span>
        </p>
        <?php /* Layout preview: the real panel markup with sample data, re-rendered by admin.ts
         * (kk_data_desk_preview) when the template above changes. The block editor needs no
         * extra box - its ServerSideRender preview below the settings is the live preview. */ ?>
        <div class="kk-data-desk-widget-preview" data-kk-data-desk-widget-preview data-kk-kind="panel">
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
        $templates = DataDesk::templatesOfKind(DataDesk::KIND_PANEL);
        $first = DataDesk::firstTemplateOfKind(DataDesk::KIND_PANEL);
        $instance['template'] = isset($templates[$templateId]) ? $templateId : ($first['id'] ?? '');

        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);

        return $instance;
    }
}

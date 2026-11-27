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
        // widget picked it) falls back to the first panel template -
        // this widget only ever renders panels. With no panel template
        // at all the widget renders nothing.
        if ($template === null || $template['kind'] !== DataDesk::KIND_PANEL) {
            $template = DataDesk::firstTemplateOfKind(DataDesk::KIND_PANEL);
        }

        if ($template === null) {
            return;
        }

        // Per-widget overrides (symbol, days, hidden stats) apply
        // on top of the template; the template itself is never
        // modified.
        $template = DataDesk::applyWidgetOverrides($template, $instance);

        $parsed = DataDesk::parseSymbol($template['symbol']);
        $parsed = $parsed ?? ['market' => 'gold', 'symbol' => 'antam'];

        // The widget title IS the panel title: it travels into the
        // panel, where {label} / {day} resolve, and no separate
        // widget heading renders above it - one title, resolved,
        // inside the panel. An empty widget title leaves the
        // template title in place. (A widget heading here used to
        // print the raw tokens a second time, above the panel.)
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $panelTitle = trim($title) !== '' ? $title : (string) ($template['title'] ?? '');
        $data = DataDesk::panelData($parsed['market'], $parsed['symbol'], (int) $template['days']);

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance));

        get_template_part('template-parts/data-desk/panel', null, [
            'data' => $data,
            'palette' => $template['palette'],
            'days' => (int) $template['days'],
            'title' => $panelTitle,
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
        // template picked here falls back to the first panel template,
        // the same fallback widget() applies. With no panel template
        // the preview says so instead of rendering.
        $previewTemplate = $templateId !== '' ? DataDesk::getTemplate($templateId) : null;
        if ($previewTemplate === null || $previewTemplate['kind'] !== DataDesk::KIND_PANEL) {
            $previewTemplate = DataDesk::firstTemplateOfKind(DataDesk::KIND_PANEL);
        }
        // Per-widget overrides on top of the template: the symbol
        // and days replace the template's, and a stat can be
        // hidden here only. Empty means "use the template's".
        $symbolOverride = isset($instance['symbol_override']) ? (string) $instance['symbol_override'] : '';
        $daysOverride = isset($instance['days_override']) ? (int) $instance['days_override'] : 0;
        $templateSymbolLabel = $previewTemplate !== null ? (DataDesk::symbols()[$previewTemplate['symbol']] ?? $previewTemplate['symbol']) : '';
        $templateDays = $previewTemplate !== null ? (int) $previewTemplate['days'] : DataDesk::DEFAULT_DAYS;
        $templateHidden = $previewTemplate !== null ? (array) ($previewTemplate['hidden_blocks'] ?? []) : [];
        $labelTemplate = $previewTemplate ?? DataDesk::defaultTemplate();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                data-kk-data-desk-title
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                value="<?php echo esc_attr($title); ?>"
            >
        </p>
        <?php WidgetSpacing::field($this, $instance); ?>
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
                        <?php echo esc_html(DataDesk::displayName($template)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">
                <?php esc_html_e('Manage templates on the Data Desk Templates page.', 'koperasikita'); ?>
            </span>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('symbol_override')); ?>">
                <?php esc_html_e('Symbol', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                data-kk-data-desk-symbol-override
                id="<?php echo esc_attr($this->get_field_id('symbol_override')); ?>"
                name="<?php echo esc_attr($this->get_field_name('symbol_override')); ?>"
            >
                <option value="" <?php selected($symbolOverride, ''); ?>>
                    <?php
                    /* translators: %s: the template's symbol label. */
                    printf(esc_html__('Use template (%s)', 'koperasikita'), esc_html($templateSymbolLabel));
                    ?>
                </option>
                <?php foreach (DataDesk::symbols() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($symbolOverride, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('days_override')); ?>">
                <?php esc_html_e('Days', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                data-kk-data-desk-days-override
                id="<?php echo esc_attr($this->get_field_id('days_override')); ?>"
                name="<?php echo esc_attr($this->get_field_name('days_override')); ?>"
                value="<?php echo $daysOverride > 0 ? esc_attr((string) $daysOverride) : ''; ?>"
                placeholder="<?php echo esc_attr((string) $templateDays); ?>"
                min="3"
                max="30"
                step="1"
            >
            <span class="description">
                <?php esc_html_e('Empty uses the template days.', 'koperasikita'); ?>
            </span>
        </p>
        <p class="description">
            <?php esc_html_e('Show or hide each stat for this widget only. The template is unchanged.', 'koperasikita'); ?>
        </p>
        <?php foreach (DataDesk::STAT_BLOCKS as $block) : ?>
            <?php
            $templateHides = in_array($block, $templateHidden, true);
            $widgetHides = !empty($instance['hide_' . $block]);
            ?>
            <p>
                <?php if (!$templateHides) : ?>
                    <input type="hidden" name="<?php echo esc_attr($this->get_field_name('show_' . $block)); ?>" value="0">
                <?php endif; ?>
                <label>
                    <input
                        type="checkbox"
                        data-kk-data-desk-show-<?php echo esc_attr($block); ?>
                        name="<?php echo esc_attr($this->get_field_name('show_' . $block)); ?>"
                        value="1"
                        <?php checked(!$templateHides && !$widgetHides); ?>
                        <?php disabled($templateHides); ?>
                    >
                    <?php echo esc_html(DataDesk::statLabel($labelTemplate, $block)); ?>
                </label>
                <?php if ($templateHides) : ?>
                    <span class="description">
                        <?php esc_html_e('Hidden by the template.', 'koperasikita'); ?>
                    </span>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
        <?php /* Layout preview: the real panel markup with sample data, re-rendered by admin.ts
         * (kk_data_desk_preview) when the template above changes. The block editor needs no
         * extra box - its ServerSideRender preview below the settings is the live preview. */ ?>
        <div class="kk-data-desk-widget-preview" data-kk-data-desk-widget-preview data-kk-kind="panel">
            <p class="kk-data-desk-widget-preview-label"><?php esc_html_e('Layout preview — sample data', 'koperasikita'); ?></p>
            <div data-kk-data-desk-widget-preview-body>
                <?php if ($previewTemplate !== null) : ?>
                    <?php
                    // The preview shows what the widget will render:
                    // the template with the widget's overrides, and
                    // the widget title in place of the template
                    // title when one is set (see widget()).
                    $preview = DataDesk::applyWidgetOverrides($previewTemplate, $instance);
                    if (trim($title) !== '') {
                        $preview['title'] = $title;
                    }
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                    echo DataDesk::renderPreview($preview);
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

        // Per-widget overrides: an unknown symbol or an empty
        // days value means "use the template's". The stat
        // show/hide arrives either as hide_* (block editor) or
        // as show_* (classic form checkbox); a missing field
        // keeps the previous value.
        $symbolOverride = isset($new['symbol_override']) ? (string) $new['symbol_override'] : '';
        $instance['symbol_override'] = DataDesk::parseSymbol($symbolOverride) !== null ? $symbolOverride : '';
        $daysOverride = isset($new['days_override']) ? (int) $new['days_override'] : 0;
        $instance['days_override'] = $daysOverride > 0 ? max(3, min(30, $daysOverride)) : 0;
        foreach (DataDesk::STAT_BLOCKS as $block) {
            if (isset($new['hide_' . $block])) {
                $instance['hide_' . $block] = !empty($new['hide_' . $block]);
            } elseif (isset($new['show_' . $block])) {
                $instance['hide_' . $block] = empty($new['show_' . $block]);
            } else {
                $instance['hide_' . $block] = !empty($old['hide_' . $block]);
            }
        }

        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Gold;

/**
 * Koperasi Kita -> Data Desk Templates: the Data Desk template manager.
 *
 * A template is a saved Data Desk configuration — a panel (one symbol
 * as a chart + stats) or a table (the Gold Data Desk table of brands).
 * Widgets pick a template instead of carrying their own settings, so
 * a template is shared, not per-widget ("bukan widget pribadi").
 *
 * Two templates are built in and can never be deleted or edited:
 * Default (panel) and Default Table. To change one, duplicate it
 * into a new template and edit that one instead. Custom templates
 * can be edited, duplicated and deleted. Every string is i18n.
 */
final class DataDeskTemplatesPage
{
    public const PAGE_SLUG = 'kk-data-desk-templates';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $notice = self::handleActions();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $id = isset($_GET['id']) ? sanitize_key((string) wp_unslash($_GET['id'])) : '';

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Data Desk Templates', 'koperasikita'); ?></h1>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <?php if ($action === 'new' || ($action === 'edit' && $id !== '')) : ?>
                <?php self::renderForm($action === 'edit' ? $id : '', $pageUrl); ?>
            <?php else : ?>
                <p>
                    <a href="<?php echo esc_url(add_query_arg('action', 'new', $pageUrl)); ?>" class="button button-primary">
                        <?php esc_html_e('Add New Template', 'koperasikita'); ?>
                    </a>
                </p>
                <?php self::renderList($pageUrl); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle the save / delete / duplicate submissions. Returns a
     * notice to show, or '' when nothing was done.
     */
    private static function handleActions(): string
    {
        if (isset($_POST['kk_data_desk_template_nonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_data_desk_template', 'kk_data_desk_template_nonce');

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
            $raw = wp_unslash($_POST);
            $id = isset($raw['kk_template_id']) ? sanitize_key((string) $raw['kk_template_id']) : '';
            $data = [
                'name' => isset($raw['kk_template_name']) ? sanitize_text_field((string) $raw['kk_template_name']) : '',
                'kind' => isset($raw['kk_template_kind']) ? sanitize_key((string) $raw['kk_template_kind']) : DataDesk::KIND_PANEL,
                'symbol' => isset($raw['kk_template_symbol']) ? sanitize_text_field((string) $raw['kk_template_symbol']) : 'gold:antam',
                'brands' => isset($raw['kk_template_brands']) && is_array($raw['kk_template_brands'])
                    ? array_values(array_map('sanitize_key', $raw['kk_template_brands']))
                    : [],
                'palette' => isset($raw['kk_template_palette']) ? sanitize_key((string) $raw['kk_template_palette']) : DataDesk::DEFAULT_PALETTE,
                'days' => isset($raw['kk_template_days']) ? (int) $raw['kk_template_days'] : DataDesk::DEFAULT_DAYS,
                'title' => isset($raw['kk_template_title']) ? sanitize_text_field((string) $raw['kk_template_title']) : '',
            ];

            DataDesk::saveTemplate($id, $data);

            wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- delete/duplicate use a nonce in the URL.
        if (isset($_GET['kk_data_desk_delete']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_data_desk_delete', '_wpnonce');

            $id = sanitize_key((string) wp_unslash($_GET['kk_data_desk_delete']));
            DataDesk::deleteTemplate($id);

            wp_safe_redirect(add_query_arg('deleted', '1', admin_url('admin.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- duplicate uses a nonce in the URL.
        if (isset($_GET['kk_data_desk_duplicate']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_data_desk_duplicate', '_wpnonce');

            $id = sanitize_key((string) wp_unslash($_GET['kk_data_desk_duplicate']));
            $source = DataDesk::getTemplate($id);
            $copy = $source;
            $copy['name'] = sprintf(
                /* translators: %s: template name. */
                __('%s (copy)', 'koperasikita'),
                $source['name']
            );
            $newId = DataDesk::saveTemplate('', $copy);

            wp_safe_redirect(add_query_arg(
                ['action' => 'edit', 'id' => $newId, 'saved' => '1'],
                admin_url('admin.php?page=' . self::PAGE_SLUG)
            ));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved/deleted flags are display-only.
        if (isset($_GET['saved']) && $_GET['saved'] === '1') {
            return __('Template saved.', 'koperasikita');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved/deleted flags are display-only.
        if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
            return __('Template deleted.', 'koperasikita');
        }

        return '';
    }

    /**
     * The template list: name, a visual preview of the layout, kind,
     * what it shows, palette, days, and actions. The built-in
     * defaults carry a "Default" badge and offer Duplicate only —
     * they can never be edited or deleted. The preview is the real
     * panel / table markup with sample data, scaled down, so the
     * list shows the layout, not just its name.
     */
    private static function renderList(string $pageUrl): void
    {
        $templates = DataDesk::templates();
        $kinds = DataDesk::kinds();
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Preview', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Kind', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Shows', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Palette', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Days', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Actions', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $id => $template) : ?>
                    <?php
                    $isDefault = $id === DataDesk::DEFAULT_TEMPLATE_ID || $id === DataDesk::DEFAULT_TABLE_TEMPLATE_ID;
                    $shows = $template['kind'] === DataDesk::KIND_TABLE
                        ? ($template['brands'] !== [] ? implode(', ', array_map(static fn(string $slug): string => Gold::brands()[$slug] ?? $slug, $template['brands'])) : __('All brands', 'koperasikita'))
                        : (DataDesk::symbols()[$template['symbol']] ?? $template['symbol']);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($template['name']); ?></strong>
                            <?php if ($isDefault) : ?>
                                <span class="description"><?php esc_html_e('Default', 'koperasikita'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="kk-data-desk-list-preview">
                                <div class="kk-data-desk-list-preview-inner">
                                    <?php
                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                                    echo DataDesk::renderPreview($template);
                                    ?>
                                </div>
                            </div>
                        </td>
                        <td><?php echo esc_html($kinds[$template['kind']] ?? $template['kind']); ?></td>
                        <td><?php echo esc_html($shows); ?></td>
                        <td><?php echo esc_html(DataDesk::PALETTES[$template['palette']] ?? $template['palette']); ?></td>
                        <td><?php echo $template['kind'] === DataDesk::KIND_PANEL ? esc_html((string) $template['days']) : '&mdash;'; ?></td>
                        <td>
                            <?php if (!$isDefault) : ?>
                                <a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $id], $pageUrl)); ?>"><?php esc_html_e('Edit', 'koperasikita'); ?></a>
                                |
                            <?php endif; ?>
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('kk_data_desk_duplicate', $id, $pageUrl), 'kk_data_desk_duplicate')); ?>"><?php esc_html_e('Duplicate', 'koperasikita'); ?></a>
                            <?php if (!$isDefault) : ?>
                                |
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('kk_data_desk_delete', $id, $pageUrl), 'kk_data_desk_delete')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this template?', 'koperasikita')); ?>');"><?php esc_html_e('Delete', 'koperasikita'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e('Widgets pick a template instead of carrying their own settings. The built-in defaults can never be deleted — duplicate one to edit it as a new template.', 'koperasikita'); ?>
        </p>
        <?php
    }

    /**
     * The create / edit form. Kind switches which fields apply:
     * panel shows Symbol + Days, table shows Brands. The toggle is
     * a tiny inline script (the admin script is not enqueued here).
     */
    private static function renderForm(string $id, string $pageUrl): void
    {
        $template = $id !== '' ? DataDesk::getTemplate($id) : [
            'id' => '',
            'name' => '',
            'kind' => DataDesk::KIND_PANEL,
            'symbol' => 'gold:antam',
            'brands' => [],
            'palette' => DataDesk::DEFAULT_PALETTE,
            'days' => DataDesk::DEFAULT_DAYS,
            'title' => '',
        ];
        $isDefault = $id === DataDesk::DEFAULT_TEMPLATE_ID || $id === DataDesk::DEFAULT_TABLE_TEMPLATE_ID;
        ?>
        <h2><?php echo $id !== '' ? esc_html__('Edit Template', 'koperasikita') : esc_html__('Add New Template', 'koperasikita'); ?></h2>
        <?php if ($isDefault) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This is a built-in default template. Saving creates a new template instead of changing it.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url($pageUrl); ?>">
            <?php wp_nonce_field('kk_data_desk_template', 'kk_data_desk_template_nonce'); ?>
            <input type="hidden" name="kk_template_id" value="<?php echo esc_attr($id); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="kk-template-name"><?php esc_html_e('Name', 'koperasikita'); ?></label></th>
                    <td><input type="text" id="kk-template-name" name="kk_template_name" value="<?php echo esc_attr($template['name']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-template-kind"><?php esc_html_e('Kind', 'koperasikita'); ?></label></th>
                    <td>
                        <select id="kk-template-kind" name="kk_template_kind">
                            <?php foreach (DataDesk::kinds() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($template['kind'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr data-kk-template-panel-row>
                    <th scope="row"><label for="kk-template-symbol"><?php esc_html_e('Symbol', 'koperasikita'); ?></label></th>
                    <td>
                        <select id="kk-template-symbol" name="kk_template_symbol">
                            <?php foreach (DataDesk::symbols() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($template['symbol'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Panel only: the symbol the chart and stats show.', 'koperasikita'); ?></p>
                    </td>
                </tr>
                <tr data-kk-template-panel-row>
                    <th scope="row"><label for="kk-template-days"><?php esc_html_e('Days in the chart', 'koperasikita'); ?></label></th>
                    <td>
                        <input type="number" id="kk-template-days" name="kk_template_days" value="<?php echo esc_attr((string) $template['days']); ?>" min="3" max="30" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Panel only: how many days the chart covers (3–30).', 'koperasikita'); ?></p>
                    </td>
                </tr>
                <tr data-kk-template-table-row>
                    <th scope="row"><label for="kk-template-brands"><?php esc_html_e('Brands to show', 'koperasikita'); ?></label></th>
                    <td>
                        <?php /* Select2-style picker (see admin.ts): a searchable multi-select, not a checkbox list. */ ?>
                        <select
                            multiple
                            class="widefat"
                            data-kk-term-picker
                            id="kk-template-brands"
                            name="kk_template_brands[]"
                            style="min-height: 120px;"
                        >
                            <?php $allBrands = Gold::brands(); ?>
                            <?php foreach (Gold::enabledBrands() as $slug) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, $template['brands'], true)); ?>><?php echo esc_html($allBrands[$slug] ?? $slug); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Table only: leave empty to show all brands.', 'koperasikita'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-template-palette"><?php esc_html_e('Colour palette', 'koperasikita'); ?></label></th>
                    <td>
                        <select id="kk-template-palette" name="kk_template_palette">
                            <?php foreach (DataDesk::PALETTES as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($template['palette'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-template-title"><?php esc_html_e('Title', 'koperasikita'); ?></label></th>
                    <td>
                        <input type="text" id="kk-template-title" name="kk_template_title" value="<?php echo esc_attr($template['title']); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Leave empty for the automatic title. Use {label} for the symbol name and {day} for the number of days.', 'koperasikita'); ?></p>
                    </td>
                </tr>
            </table>
            <?php /* Live preview: the real panel / table markup with sample data, re-rendered by admin.ts
             * (kk_data_desk_preview) whenever a field above changes — kind, symbol, days, brands,
             * palette or title. The server renders the first preview so the section is never empty. */ ?>
            <h2><?php esc_html_e('Preview', 'koperasikita'); ?></h2>
            <p class="description">
                <?php esc_html_e('Live preview of this template with sample data. It updates as you change the settings above.', 'koperasikita'); ?>
            </p>
            <div id="kk-data-desk-live-preview" data-kk-data-desk-preview>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                echo DataDesk::renderPreview($template);
                ?>
            </div>
            <?php submit_button($id !== '' ? __('Update Template', 'koperasikita') : __('Add Template', 'koperasikita')); ?>
            <a href="<?php echo esc_url($pageUrl); ?>" class="button"><?php esc_html_e('Cancel', 'koperasikita'); ?></a>
        </form>
        <script>
        (function () {
            var kind = document.getElementById('kk-template-kind');
            if (!kind) { return; }
            var toggle = function () {
                var isPanel = kind.value === 'panel';
                document.querySelectorAll('[data-kk-template-panel-row]').forEach(function (row) {
                    row.style.display = isPanel ? '' : 'none';
                });
                document.querySelectorAll('[data-kk-template-table-row]').forEach(function (row) {
                    row.style.display = isPanel ? 'none' : '';
                });
            };
            kind.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }
}

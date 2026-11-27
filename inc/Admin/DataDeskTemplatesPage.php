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
 * Templates can be edited, duplicated and deleted; there are no
 * built-in defaults.
 */
final class DataDeskTemplatesPage
{
    public const PAGE_SLUG = 'kk-data-desk-templates';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $notice = self::noticeFromRequest();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $id = isset($_GET['id']) ? sanitize_key((string) wp_unslash($_GET['id'])) : '';

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);

        // Editing a template that no longer exists falls back to the
        // list — there is nothing to edit.
        if ($action === 'edit' && $id !== '' && DataDesk::getTemplate($id) === null) {
            $action = 'list';
        }
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
     * Handle the save / delete / duplicate submissions. Runs on the
     * page's load hook (see AdminMenu::registerMenu), BEFORE any
     * output: the handlers redirect after acting, and a redirect
     * cannot send headers once the admin chrome has been output —
     * calling this from render() blanked the page content after
     * every Update / Duplicate / Delete while the action itself
     * had already persisted. Returns nothing; an action exits
     * through its redirect, anything else falls through to render().
     */
    public static function handleActionsOnLoad(): void
    {
        if (isset($_POST['kk_data_desk_template_nonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_data_desk_template', 'kk_data_desk_template_nonce');

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
            $raw = wp_unslash($_POST);
            $id = isset($raw['kk_template_id']) ? sanitize_key((string) $raw['kk_template_id']) : '';

            DataDesk::saveTemplate($id, DataDesk::templateDataFromRequest($raw));

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

            if ($source === null) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
                exit;
            }

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
    }

    /**
     * The notice for the redirect the load-hook handler just sent
     * (?saved=1 / ?deleted=1), shown by render(). The actions
     * themselves live on the load hook; this only reads the flag.
     */
    private static function noticeFromRequest(): string
    {
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
     * The template list: name, kind, what it shows, palette, days,
     * and actions. Every template can be edited, duplicated and
     * deleted. The preview is not rendered inline — each row has a
     * Preview link that opens the real panel / table markup (sample
     * data) in a popup, fetched through kk_data_desk_preview.
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
                    $shows = $template['kind'] === DataDesk::KIND_TABLE
                        ? ($template['brands'] !== [] ? implode(', ', array_map(static fn(string $slug): string => Gold::brands()[$slug] ?? $slug, $template['brands'])) : __('All brands', 'koperasikita'))
                        : (DataDesk::symbols()[$template['symbol']] ?? $template['symbol']);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($template['name']); ?></strong>
                        </td>
                        <td><?php echo esc_html($kinds[$template['kind']] ?? $template['kind']); ?></td>
                        <td><?php echo esc_html($shows); ?></td>
                        <td><?php echo esc_html(DataDesk::paletteLabel($template['palette'])); ?></td>
                        <td><?php echo $template['kind'] === DataDesk::KIND_PANEL ? esc_html((string) $template['days']) : '&mdash;'; ?></td>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $id], $pageUrl)); ?>"><?php esc_html_e('Edit', 'koperasikita'); ?></a>
                            |
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('kk_data_desk_duplicate', $id, $pageUrl), 'kk_data_desk_duplicate')); ?>"><?php esc_html_e('Duplicate', 'koperasikita'); ?></a>
                            |
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('kk_data_desk_delete', $id, $pageUrl), 'kk_data_desk_delete')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this template?', 'koperasikita')); ?>');"><?php esc_html_e('Delete', 'koperasikita'); ?></a>
                            |
                            <a href="#" data-kk-data-desk-preview-link data-kk-template-id="<?php echo esc_attr($id); ?>" data-kk-template-name="<?php echo esc_attr($template['name']); ?>"><?php esc_html_e('Preview', 'koperasikita'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php /* One shared preview popup, reused by every row's Preview
         * link. The panel is filled by admin.ts (kk_data_desk_preview
         * by template id); without JS the link does nothing and the
         * list stays usable. */ ?>
        <div class="kk-data-desk-popup" id="kk-data-desk-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-data-desk-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-data-desk-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-data-desk-popup-title"></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button" data-kk-data-desk-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body" data-kk-data-desk-popup-body></div>
            </div>
        </div>
        <p class="description">
            <?php esc_html_e('Widgets pick a template instead of carrying their own settings.', 'koperasikita'); ?>
        </p>
        <?php
    }

    /**
     * The create / edit form: a sticky top bar holds every setting
     * (name, kind, symbol/days or brands, palette) with Save at the
     * right; below it the live preview IS the editor — kicker and
     * title are edited inline in the preview itself. Kind switches
     * which top-bar fields apply: panel shows Symbol + Days, table
     * shows Brands. The toggle is a tiny inline script so it runs
     * before the admin bundle loads (no flash of the wrong fields).
     */
    private static function renderForm(string $id, string $pageUrl): void
    {
        $template = $id !== '' ? DataDesk::getTemplate($id) : null;

        if ($template === null) {
            // A missing template (deleted since the link was made)
            // opens as a new one instead of editing a ghost.
            $id = '';
            $template = [
                'id' => '',
                'name' => '',
                'kind' => DataDesk::KIND_PANEL,
                'symbol' => 'gold:antam',
                'brands' => [],
                'palette' => DataDesk::DEFAULT_PALETTE,
                'days' => DataDesk::DEFAULT_DAYS,
                'title' => '',
                'kicker' => '',
                'date_line' => '',
                'stats' => DataDesk::sanitizeStats([]),
                'hidden_blocks' => [],
            ];
        }

        // Palette segments in the top bar, in the user's order:
        // Green is the dark-green palette, Auto follows the site
        // theme. Short labels fit the bar; the full palette name
        // rides along as a tooltip on each segment.
        $paletteSegments = [
            'dark-green' => __('Green', 'koperasikita'),
            'dark' => __('Dark', 'koperasikita'),
            'light' => __('Light', 'koperasikita'),
            'auto' => __('Auto', 'koperasikita'),
        ];
        ?>
        <h2><?php echo $id !== '' ? esc_html__('Edit Template', 'koperasikita') : esc_html__('Add New Template', 'koperasikita'); ?></h2>
        <form method="post" action="<?php echo esc_url($pageUrl); ?>" id="kk-data-desk-template-form">
            <?php wp_nonce_field('kk_data_desk_template', 'kk_data_desk_template_nonce'); ?>
            <input type="hidden" name="kk_template_id" value="<?php echo esc_attr($id); ?>">
            <?php /* The kicker and date line have no visible
             * rows: both are edited inline in the live preview
             * below, which writes into these hidden fields
             * (admin.ts) — the save flow reads them exactly as
             * before. The title DOES have a visible row in the
             * top bar (below): the table preview has no title
             * to click, so the header is where its title is
             * edited. */ ?>
            <input type="hidden" id="kk-template-kicker" name="kk_template_kicker" value="<?php echo esc_attr($template['kicker']); ?>">
            <input type="hidden" id="kk-template-date-line" name="kk_template_date_line" value="<?php echo esc_attr($template['date_line'] ?? ''); ?>">
            <?php /* Stat label + sub-line overrides and the hidden
             * stat blocks: edited inline in the live preview below
             * (click a label or note to edit it, eye button per
             * block to hide or show it), which writes into these
             * hidden fields (admin.ts) — the save flow reads them
             * exactly as the title/kicker. */ ?>
            <?php foreach (DataDesk::STAT_BLOCKS as $blockKey) : ?>
            <input type="hidden" id="kk-template-stat-<?php echo esc_attr($blockKey); ?>-label" name="kk_template_stat_<?php echo esc_attr($blockKey); ?>_label" value="<?php echo esc_attr($template['stats'][$blockKey]['label'] ?? ''); ?>">
            <input type="hidden" id="kk-template-stat-<?php echo esc_attr($blockKey); ?>-sub" name="kk_template_stat_<?php echo esc_attr($blockKey); ?>_sub" value="<?php echo esc_attr($template['stats'][$blockKey]['sub'] ?? ''); ?>">
            <?php endforeach; ?>
            <input type="hidden" id="kk-template-hidden-blocks" name="kk_template_hidden_blocks" value="<?php echo esc_attr(implode(',', $template['hidden_blocks'] ?? [])); ?>">
            <div class="kk-data-desk-topbar">
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--name">
                    <label for="kk-template-name"><?php esc_html_e('Name', 'koperasikita'); ?></label>
                    <input type="text" id="kk-template-name" name="kk_template_name" value="<?php echo esc_attr($template['name']); ?>" required>
                </div>
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--title">
                    <label for="kk-template-title"><?php esc_html_e('Title', 'koperasikita'); ?></label>
                    <?php /* The visible title field IS the save field
                     * (same id + name the inline preview edit writes
                     * into), so typing here refreshes the preview live
                     * and an inline edit in the preview updates this
                     * input — one field, two ways to edit it. */ ?>
                    <input type="text" id="kk-template-title" name="kk_template_title" value="<?php echo esc_attr($template['title']); ?>">
                </div>
                <div class="kk-data-desk-topbar-field">
                    <label for="kk-template-kind"><?php esc_html_e('Kind', 'koperasikita'); ?></label>
                    <select id="kk-template-kind" name="kk_template_kind">
                        <?php foreach (DataDesk::kinds() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($template['kind'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kk-data-desk-topbar-field" data-kk-template-panel-control>
                    <label for="kk-template-symbol"><?php esc_html_e('Symbol', 'koperasikita'); ?></label>
                    <select id="kk-template-symbol" name="kk_template_symbol">
                        <?php foreach (DataDesk::symbols() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($template['symbol'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--days" data-kk-template-panel-control>
                    <label for="kk-template-days"><?php esc_html_e('Days', 'koperasikita'); ?></label>
                    <input type="number" id="kk-template-days" name="kk_template_days" value="<?php echo esc_attr((string) $template['days']); ?>" min="3" max="30" step="1">
                </div>
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--brands" data-kk-template-table-control>
                    <label for="kk-template-brands"><?php esc_html_e('Brands to show', 'koperasikita'); ?></label>
                    <?php /* Select2-style picker (see admin.ts): a searchable multi-select, not a checkbox list. */ ?>
                    <select
                        multiple
                        class="widefat"
                        data-kk-term-picker
                        id="kk-template-brands"
                        name="kk_template_brands[]"
                    >
                        <?php $allBrands = Gold::brands(); ?>
                        <?php foreach (Gold::enabledBrands() as $slug) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, $template['brands'], true)); ?>><?php echo esc_html($allBrands[$slug] ?? $slug); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kk-data-desk-topbar-field">
                    <span class="kk-data-desk-topbar-label" id="kk-template-palette-label"><?php esc_html_e('Colour palette', 'koperasikita'); ?></span>
                    <div class="kk-segmented" role="group" aria-labelledby="kk-template-palette-label">
                        <?php foreach ($paletteSegments as $value => $segmentLabel) : ?>
                            <input
                                type="radio"
                                id="kk-template-palette-<?php echo esc_attr($value); ?>"
                                name="kk_template_palette_ui"
                                value="<?php echo esc_attr($value); ?>"
                                <?php checked($template['palette'], $value); ?>
                            >
                            <label for="kk-template-palette-<?php echo esc_attr($value); ?>" title="<?php echo esc_attr(DataDesk::paletteLabel($value)); ?>"><?php echo esc_html($segmentLabel); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="kk-template-palette" name="kk_template_palette" value="<?php echo esc_attr($template['palette']); ?>">
                </div>
                <div class="kk-data-desk-topbar-actions">
                    <input type="submit" name="submit" class="button button-primary" value="<?php echo esc_attr($id !== '' ? __('Update Template', 'koperasikita') : __('Add Template', 'koperasikita')); ?>">
                    <a href="<?php echo esc_url($pageUrl); ?>" class="button"><?php esc_html_e('Cancel', 'koperasikita'); ?></a>
                </div>
            </div>
            <?php /* The live preview IS the editor: the real panel /
             * table markup with sample data, re-rendered by admin.ts
             * (kk_data_desk_preview) whenever a top-bar field
             * changes. The server renders the first preview so the
             * section is never empty. */ ?>
            <?php /* Kind-aware hint: the panel preview has a title
             * to click, the table preview has none — its title is
             * edited in the top bar above, so the table hint must
             * never promise a clickable preview title. The toggle
             * script below shows the matching hint. */ ?>
            <p class="description kk-data-desk-editor-hint" data-kk-template-panel-hint>
                <?php esc_html_e("Click the title or 'Meja Data' in the preview to edit them. In the title you can use {label} for the symbol name and {day} for the number of days. Click the date line under the title to edit it. Click a stat label or note to edit it, or the eye button on a stat to hide it or show it again.", 'koperasikita'); ?>
            </p>
            <p class="description kk-data-desk-editor-hint" data-kk-template-table-hint>
                <?php esc_html_e('Edit the title in the top bar above.', 'koperasikita'); ?>
            </p>
            <div id="kk-data-desk-live-preview" data-kk-data-desk-preview>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                echo DataDesk::renderPreview($template);
                ?>
            </div>
        </form>
        <script>
        (function () {
            var kind = document.getElementById('kk-template-kind');
            if (!kind) { return; }
            var toggle = function () {
                var isPanel = kind.value === 'panel';
                document.querySelectorAll('[data-kk-template-panel-control]').forEach(function (control) {
                    control.style.display = isPanel ? '' : 'none';
                });
                document.querySelectorAll('[data-kk-template-table-control]').forEach(function (control) {
                    control.style.display = isPanel ? 'none' : '';
                });
                document.querySelectorAll('[data-kk-template-panel-hint]').forEach(function (hint) {
                    hint.style.display = isPanel ? '' : 'none';
                });
                document.querySelectorAll('[data-kk-template-table-hint]').forEach(function (hint) {
                    hint.style.display = isPanel ? 'none' : '';
                });
            };
            kind.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }
}

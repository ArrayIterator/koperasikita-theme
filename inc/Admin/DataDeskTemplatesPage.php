<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Gold;
use KoperasiKita\Icons\Icon;

/**
 * Koperasi Kita -> Data Desk Templates: the Data Desk template manager.
 *
 * A template is a saved Data Desk configuration - a panel (one symbol
 * as a chart + stats) or a table (the Gold Data Desk table of brands).
 * Widgets pick a template instead of carrying their own settings, so
 * a template is shared, not per-widget ("bukan widget pribadi").
 * User templates can be edited, duplicated and deleted. The
 * built-in default (Default panel) carries a lock badge instead:
 * it can be used (duplicated into your own template), edited
 * (saving stores an override) and restored (deleting the override
 * brings back the code-defined values) - never deleted, never frozen.
 * The second built-in (Antam 7 Hari panel) works the same way.
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
        // list - there is nothing to edit.
        if ($action === 'edit' && $id !== '' && DataDesk::getTemplate($id) === null) {
            $action = 'list';
        }
        ?>
        <div class="wrap kk-data-desk-page">
            <h1 class="kk-data-desk-title"><?php esc_html_e('Data Desk Templates', 'koperasikita'); ?></h1>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <?php if ($action === 'new' || ($action === 'edit' && $id !== '')) : ?>
                <?php self::renderForm($action === 'edit' ? $id : '', $pageUrl); ?>
            <?php else : ?>
                <section class="kk-data-desk-card">
                    <p class="kk-data-desk-card-actions">
                        <a href="<?php echo esc_url(add_query_arg('action', 'new', $pageUrl)); ?>" class="button button-primary">
                            <?php esc_html_e('Add New Template', 'koperasikita'); ?>
                        </a>
                    </p>
                    <?php self::renderList($pageUrl); ?>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle the save / delete / duplicate submissions. Runs on the
     * page's load hook (see AdminMenu::registerMenu), BEFORE any
     * output: the handlers redirect after acting, and a redirect
     * cannot send headers once the admin chrome has been output -
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
            $deleted = DataDesk::deleteTemplate($id);

            $url = admin_url('admin.php?page=' . self::PAGE_SLUG);

            if ($deleted) {
                $url = add_query_arg('deleted', '1', $url);
            }

            wp_safe_redirect($url);
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restore uses a nonce in the URL.
        if (isset($_GET['kk_data_desk_restore']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_data_desk_restore', '_wpnonce');

            $id = sanitize_key((string) wp_unslash($_GET['kk_data_desk_restore']));
            $restored = DataDesk::restoreTemplate($id);

            // Reverting from inside the editor returns there, so the
            // editor shows the built-in values right away; from the
            // list it returns to the list. Anything else is not a
            // destination, so it falls through to the list.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- return flag is display-only.
            $backToEdit = isset($_GET['return']) && sanitize_key((string) wp_unslash($_GET['return'])) === 'edit';

            if ($backToEdit) {
                $url = add_query_arg(['action' => 'edit', 'id' => $id], admin_url('admin.php?page=' . self::PAGE_SLUG));
            } else {
                $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
            }

            if ($restored) {
                $url = add_query_arg('restored', '1', $url);
            }

            wp_safe_redirect($url);
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

        // Bulk duplicate from the list table: action=duplicate (or
        // action2, the bottom Apply button) + template[] + the
        // bulk-templates nonce the table itself renders. A built-in
        // duplicates into a new user template, exactly like Use;
        // nothing is deleted here.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk duplicate uses the table nonce.
        $bulkAction = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
        if ($bulkAction === '' || $bulkAction === '-1') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk duplicate uses the table nonce.
            $bulkAction = isset($_GET['action2']) ? sanitize_key((string) wp_unslash($_GET['action2'])) : '';
        }

        if ($bulkAction === 'duplicate' && isset($_GET['template']) && is_array($_GET['template'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('bulk-templates');

            $ids = array_values(array_map('sanitize_key', wp_unslash($_GET['template'])));
            $made = 0;

            foreach ($ids as $id) {
                $source = DataDesk::getTemplate($id);

                if ($source === null) {
                    continue;
                }

                $copy = $source;
                $copy['name'] = sprintf(
                    /* translators: %s: template name. */
                    __('%s (copy)', 'koperasikita'),
                    $source['name']
                );
                DataDesk::saveTemplate('', $copy);
                $made++;
            }

            $url = admin_url('admin.php?page=' . self::PAGE_SLUG);

            if ($made > 0) {
                $url = add_query_arg('duplicated', '1', $url);
            }

            wp_safe_redirect($url);
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restored flag is display-only.
        if (isset($_GET['restored']) && $_GET['restored'] === '1') {
            return __('Template restored to its built-in values.', 'koperasikita');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- duplicated flag is display-only.
        if (isset($_GET['duplicated']) && $_GET['duplicated'] === '1') {
            return __('Templates duplicated.', 'koperasikita');
        }

        return '';
    }

    /**
     * The 13 placeholder tokens with what each resolves to and a
     * live sample from the template's own preview series. Shared
     * by the help tab and the JSON blob the token tooling reads,
     * so the two can never drift apart. {day} and {days} resolve
     * to the same number of days; {day} is the one the title
     * takes, {days} the one the hint used to list alone.
     *
     * @return array<int, array{token: string, desc: string, sample: string}>
     */
    private static function placeholderTokenRows(array $template): array
    {
        $placeholders = DataDesk::sanitizePlaceholders($template['placeholders'] ?? []);
        $sampleData = DataDesk::previewData($template);
        $days = (int) ($template['days'] ?? DataDesk::DEFAULT_DAYS);
        $tokens = [
            ['{start}', __('First date of the chart range', 'koperasikita')],
            ['{end}', __('Last date of the chart range', 'koperasikita')],
            ['{date}', __('Last date (today)', 'koperasikita')],
            ['{year}', __('Year of the last date', 'koperasikita')],
            ['{label}', __('Symbol name', 'koperasikita')],
            ['{day}', __('Number of days in the chart', 'koperasikita')],
            ['{days}', __('Number of days in the chart', 'koperasikita')],
            ['{arrow}', __('Direction arrow (up or down)', 'koperasikita')],
            ['{delta}', __('Change amount, coloured by direction', 'koperasikita')],
            ['{delta_pct}', __('Change in percent', 'koperasikita')],
            ['{spread}', __('Sell–buyback spread', 'koperasikita')],
            ['{streak}', __('Consecutive up days', 'koperasikita')],
            ['{zone}', __('Zone name, in the zone colour (auto follows the trend)', 'koperasikita')],
        ];

        $rows = [];

        foreach ($tokens as [$token, $desc]) {
            $rows[] = [
                'token' => $token,
                'desc' => $desc,
                'sample' => wp_strip_all_tags(DataDesk::replacePlaceholders($token, $sampleData, $days, $placeholders)),
            ];
        }

        return $rows;
    }

    /**
     * Contextual help tab for this page: the placeholder
     * reference that used to be a card on the edit page. The
     * samples come from the edited template on the edit page
     * and from the built-in default on the list page. The Insert
     * buttons and the Configure button live here now; admin.ts
     * binds them document-wide, so they work from the tab.
     */
    public static function addHelpTabs(): void
    {
        $screen = get_current_screen();

        if (!$screen instanceof \WP_Screen) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $id = isset($_GET['id']) ? sanitize_key((string) wp_unslash($_GET['id'])) : '';
        $template = ($action === 'edit' && $id !== '' && DataDesk::getTemplate($id) !== null)
            ? DataDesk::getTemplate($id)
            : DataDesk::defaultTemplate();

        $content = '<p>' . esc_html__("Placeholders resolve from the panel's own data. Insert opens a popup showing what the placeholder resolves to - pick the field it goes into. You can also right-click a title, date line or stat note, or type { while editing one. The title takes {label} and {day} only.", 'koperasikita') . '</p>';
        $content .= '<table class="widefat striped"><thead><tr><th>' . esc_html__('Placeholder', 'koperasikita') . '</th><th>' . esc_html__('Resolves to', 'koperasikita') . '</th><th>' . esc_html__('Sample', 'koperasikita') . '</th><th></th></tr></thead><tbody>';

        foreach (self::placeholderTokenRows($template) as $row) {
            $content .= '<tr><td><code>' . esc_html($row['token']) . '</code></td><td>' . esc_html($row['desc']) . '</td><td>' . esc_html($row['sample']) . '</td><td><button type="button" class="button" data-kk-placeholder-insert="' . esc_attr($row['token']) . '">' . esc_html__('Insert', 'koperasikita') . '</button></td></tr>';
        }

        $content .= '</tbody></table>';
        $content .= '<p><button type="button" class="button button-primary" data-kk-placeholder-configure>' . esc_html__('Configure placeholders', 'koperasikita') . '</button></p>';

        $screen->add_help_tab([
            'id' => 'kk-data-desk-placeholders',
            'title' => __('Placeholders', 'koperasikita'),
            'content' => $content,
        ]);
    }

    /**
     * The template list: name, kind, what it shows, palette, days,
     * and actions. User templates can be edited, duplicated and
     * deleted; the built-in carries a lock badge and offers Use
     * (duplicate into your own template), Edit (saving stores an
     * override) and Restore (only while an override exists). The
     * preview is not rendered inline - each row has a Preview link
     * that opens the real panel / table markup (sample data) in a
     * popup, fetched through kk_data_desk_preview.
     */
    private static function renderList(string $pageUrl): void
    {
        $table = new DataDeskTemplatesTable(DataDesk::templates(), $pageUrl);
        $table->prepare_items();
        ?>
        <?php /* Start from an existing template: pick any built-in
         * or user template as the base - it is duplicated into a
         * new editable user template and the editor opens on the
         * copy (the same duplicate the list's Use action runs). A
         * plain new template stays one click away above; this form
         * adds no step to it. The nonce is for the duplicate
         * action, which the load-hook handler verifies. */ ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="kk-data-desk-card-actions kk-data-desk-start-from">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('kk_data_desk_duplicate')); ?>">
            <label for="kk-data-desk-start-from"><?php esc_html_e('Or start from an existing template:', 'koperasikita'); ?></label>
            <select id="kk-data-desk-start-from" name="kk_data_desk_duplicate">
                <?php foreach (DataDesk::templates() as $templateId => $template) : ?>
                    <option value="<?php echo esc_attr($templateId); ?>"><?php echo esc_html(DataDesk::displayName($template)); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="<?php echo esc_attr(__('Use template', 'koperasikita')); ?>">
        </form>
        <form method="get" action="<?php echo esc_url($pageUrl); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <?php $table->search_box(__('Search Templates', 'koperasikita'), 'kk-template'); ?>
            <?php $table->display(); ?>
        </form>
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
        <p class="description kk-data-desk-note">
            <?php esc_html_e('Widgets pick a template instead of carrying their own settings.', 'koperasikita'); ?>
        </p>
        <?php
    }

    /**
     * The create / edit form: a sticky top bar holds every setting
     * (name, kind, symbol/days or brands, palette) with Save at the
     * right; below it the live preview IS the editor - kicker and
     * title are edited inline in the preview itself. Kind switches
     * which top-bar fields apply: panel shows Symbol, table shows
     * Brands; Days applies to both (it is the panel's chart window
     * and the table chart's window). The toggle is a tiny inline
     * script so it runs before the admin bundle loads (no flash of
     * the wrong fields).
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
                'headers' => [],
                'hidden_blocks' => [],
                'placeholders' => DataDesk::sanitizePlaceholders([]),
                'table_style' => DataDesk::DEFAULT_TABLE_STYLE,
                'title_display' => DataDesk::DEFAULT_TITLE_DISPLAY,
                'font_size' => DataDesk::DEFAULT_FONT_SIZE,
                'cell_padding' => DataDesk::DEFAULT_CELL_PADDING,
                'chart_animate' => true,
                'chart_colors' => [],
                'header_bg' => '',
                'header_text' => '',
            ];
        }

        // Placeholder settings for this template, sanitised once
        // here: the hidden fields, the placeholders section and the
        // settings popup below all read these values.
        $placeholders = DataDesk::sanitizePlaceholders($template['placeholders'] ?? []);

        // Table display settings for this template, sanitised
        // once here: the table-settings card below reads these
        // values, and the save flow reads the same fields.
        $tableStyle = DataDesk::sanitizeTableStyle($template['table_style'] ?? DataDesk::DEFAULT_TABLE_STYLE);
        $titleDisplay = DataDesk::sanitizeTitleDisplay($template['title_display'] ?? DataDesk::DEFAULT_TITLE_DISPLAY);
        $fontSize = DataDesk::sanitizeFontSize($template['font_size'] ?? DataDesk::DEFAULT_FONT_SIZE);
        $cellPadding = DataDesk::sanitizeCellPadding($template['cell_padding'] ?? DataDesk::DEFAULT_CELL_PADDING);
        $chartAnimate = !isset($template['chart_animate']) || (bool) $template['chart_animate'];
        $headerBg = DataDesk::sanitizeHexColor($template['header_bg'] ?? '');
        $headerText = DataDesk::sanitizeHexColor($template['header_text'] ?? '');

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
        <h2 class="kk-data-desk-card-title"><?php echo $id !== '' ? esc_html__('Edit Template', 'koperasikita') : esc_html__('Add New Template', 'koperasikita'); ?></h2>
        <?php if ($id !== '' && DataDesk::isBuiltIn($id)) : ?>
            <p class="description">
                <?php esc_html_e('This is a built-in template. Saving stores your changes as an override; Restore on the list brings back the built-in values.', 'koperasikita'); ?>
            </p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url($pageUrl); ?>" id="kk-data-desk-template-form">
            <?php wp_nonce_field('kk_data_desk_template', 'kk_data_desk_template_nonce'); ?>
            <input type="hidden" name="kk_template_id" value="<?php echo esc_attr($id); ?>">
            <?php /* The kicker and date line have no visible
             * rows: the kicker is edited inline in the live
             * preview below, the date line in its popup - both
             * write into these hidden fields (admin.ts), and
             * the save flow reads them exactly as before. The
             * title DOES have a visible row in the top bar
             * (below): the table preview has no title to
             * click, so the header is where its title is
             * edited. */ ?>
            <input type="hidden" id="kk-template-kicker" name="kk_template_kicker" value="<?php echo esc_attr($template['kicker']); ?>">
            <input type="hidden" id="kk-template-date-line" name="kk_template_date_line" value="<?php echo esc_attr($template['date_line'] ?? ''); ?>">
            <?php /* Stat label + sub-line overrides and the hidden
             * stat blocks: edited in the popup below (click a stat
             * block in the live preview, eye button per block to
             * hide or show it), which writes into these hidden
             * fields (admin.ts) - the save flow reads them exactly
             * as the title/kicker. */ ?>
            <?php foreach (DataDesk::STAT_BLOCKS as $blockKey) : ?>
            <input type="hidden" id="kk-template-stat-<?php echo esc_attr($blockKey); ?>-label" name="kk_template_stat_<?php echo esc_attr($blockKey); ?>_label" value="<?php echo esc_attr($template['stats'][$blockKey]['label'] ?? ''); ?>">
            <input type="hidden" id="kk-template-stat-<?php echo esc_attr($blockKey); ?>-sub" name="kk_template_stat_<?php echo esc_attr($blockKey); ?>_sub" value="<?php echo esc_attr($template['stats'][$blockKey]['sub'] ?? ''); ?>">
            <?php endforeach; ?>
            <?php /* Table column header overrides: edited inline in
             * the live preview below (click a header to edit it),
             * which writes into these hidden fields (admin.ts) -
             * the save flow reads them exactly as the stat texts. */ ?>
            <?php foreach (DataDesk::HEADER_FIELDS as $headerKey) : ?>
            <input type="hidden" id="kk-template-header-<?php echo esc_attr($headerKey); ?>" name="kk_template_header_<?php echo esc_attr($headerKey); ?>" value="<?php echo esc_attr($template['headers'][$headerKey] ?? ''); ?>">
            <?php endforeach; ?>
            <input type="hidden" id="kk-template-hidden-blocks" name="kk_template_hidden_blocks" value="<?php echo esc_attr(implode(',', $template['hidden_blocks'] ?? [])); ?>">
            <?php /* Placeholder settings: the date format, the
             * percent type and the zone colour. Edited in the
             * settings popup below (admin.ts), which writes into
             * these hidden fields; the save flow reads them
             * exactly as the stat texts. */ ?>
            <input type="hidden" id="kk-template-placeholder-date-format" name="kk_template_placeholder_date_format" value="<?php echo esc_attr($placeholders['date_format']); ?>">
            <input type="hidden" id="kk-template-placeholder-percent" name="kk_template_placeholder_percent" value="<?php echo esc_attr($placeholders['percent']); ?>">
            <input type="hidden" id="kk-template-placeholder-zone-color" name="kk_template_placeholder_zone_color" value="<?php echo esc_attr($placeholders['zone_color']); ?>">
            <div class="kk-data-desk-topbar">
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--name">
                    <label for="kk-template-name"><?php esc_html_e('Name', 'koperasikita'); ?></label>
                    <input type="text" id="kk-template-name" name="kk_template_name" value="<?php echo esc_attr($template['name']); ?>" required>
                </div>
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--title" data-kk-template-table-control>
                    <label for="kk-template-title"><?php esc_html_e('Title', 'koperasikita'); ?></label>
                    <?php /* The title field IS the save field (same id +
                     * name the inline preview edit writes into), so
                     * typing here refreshes the preview live and an
                     * inline edit in the preview updates this input -
                     * one field, two ways to edit it. Table-only: a
                     * panel's title is edited inline in the preview,
                     * so the top bar does not need this field. */ ?>
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
                <div class="kk-data-desk-topbar-field kk-data-desk-topbar-field--days">
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
                            <label class="button" for="kk-template-palette-<?php echo esc_attr($value); ?>" title="<?php echo esc_attr(DataDesk::paletteLabel($value)); ?>"><?php echo esc_html($segmentLabel); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="kk-template-palette" name="kk_template_palette" value="<?php echo esc_attr($template['palette']); ?>">
                </div>
                <div class="kk-data-desk-topbar-actions">
                    <?php if ($id !== '' && DataDesk::isBuiltIn($id)) : ?>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['kk_data_desk_restore' => $id, 'return' => 'edit'], $pageUrl), 'kk_data_desk_restore')); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Discard your changes and restore this template to its built-in values?', 'koperasikita')); ?>');"><?php esc_html_e('Revert to default', 'koperasikita'); ?></a>
                    <?php endif; ?>
                    <input type="submit" name="submit" class="button button-primary" value="<?php echo esc_attr($id !== '' ? __('Update Template', 'koperasikita') : __('Add Template', 'koperasikita')); ?>">
                    <a href="<?php echo esc_url($pageUrl); ?>" class="button"><?php esc_html_e('Cancel', 'koperasikita'); ?></a>
                </div>
            </div>
            <?php /* Table display settings: style, title placement,
             * type size, cell padding, chart animation and colours.
             * Table-only (the toggle script below hides it for
             * panels); the values ride along in the same form, so
             * the native save and the AJAX save both read them, and
             * admin.ts sends them with every preview refresh. */ ?>
            <div class="kk-data-desk-table-settings" data-kk-template-table-control>
                <div class="kk-data-desk-table-settings-field">
                    <label for="kk-template-table-style"><?php esc_html_e('Table style', 'koperasikita'); ?></label>
                    <select id="kk-template-table-style" name="kk_template_table_style">
                        <?php foreach (DataDesk::tableStyles() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($tableStyle, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kk-data-desk-table-settings-field">
                    <label for="kk-template-title-display"><?php esc_html_e('Title display', 'koperasikita'); ?></label>
                    <select id="kk-template-title-display" name="kk_template_title_display">
                        <?php foreach (DataDesk::titleDisplays() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($titleDisplay, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kk-data-desk-table-settings-field kk-data-desk-table-settings-field--number">
                    <label for="kk-template-font-size"><?php esc_html_e('Font size (px)', 'koperasikita'); ?></label>
                    <input type="number" id="kk-template-font-size" name="kk_template_font_size" value="<?php echo esc_attr((string) $fontSize); ?>" min="12" max="18" step="1">
                </div>
                <div class="kk-data-desk-table-settings-field kk-data-desk-table-settings-field--number">
                    <label for="kk-template-cell-padding"><?php esc_html_e('Cell padding (px)', 'koperasikita'); ?></label>
                    <input type="number" id="kk-template-cell-padding" name="kk_template_cell_padding" value="<?php echo esc_attr((string) $cellPadding); ?>" min="4" max="16" step="1">
                </div>
                <div class="kk-data-desk-table-settings-field kk-data-desk-table-settings-field--check">
                    <label for="kk-template-chart-animate"><?php esc_html_e('Animate chart', 'koperasikita'); ?></label>
                    <input type="checkbox" id="kk-template-chart-animate" name="kk_template_chart_animate" value="1" <?php checked($chartAnimate); ?>>
                    <span class="description"><?php esc_html_e('Draw the chart lines on load. Always off when reduced motion is on.', 'koperasikita'); ?></span>
                </div>
                <div class="kk-data-desk-table-settings-field kk-data-desk-table-settings-field--colors">
                    <span class="kk-data-desk-topbar-label"><?php esc_html_e('Chart line colours', 'koperasikita'); ?></span>
                    <?php foreach (Gold::enabledBrands() as $slug) : ?>
                        <label class="kk-data-desk-chart-color">
                            <input
                                type="color"
                                id="kk-template-chart-color-<?php echo esc_attr($slug); ?>"
                                name="kk_template_chart_color_<?php echo esc_attr($slug); ?>"
                                value="<?php echo esc_attr(DataDesk::chartColorFor($slug, $template)); ?>"
                                data-kk-chart-color="<?php echo esc_attr($slug); ?>"
                                data-kk-chart-color-default="<?php echo esc_attr(DataDesk::chartColorFor($slug, ['chart_colors' => []])); ?>"
                            >
                            <?php echo esc_html(Gold::brands()[$slug] ?? $slug); ?>
                        </label>
                    <?php endforeach; ?>
                    <button type="button" class="button" data-kk-chart-colors-reset><?php esc_html_e('Reset colours', 'koperasikita'); ?></button>
                </div>
                <div class="kk-data-desk-table-settings-field kk-data-desk-table-settings-field--colors">
                    <span class="kk-data-desk-topbar-label"><?php esc_html_e('Header colours', 'koperasikita'); ?></span>
                    <label class="kk-data-desk-chart-color">
                        <span><?php esc_html_e('Background', 'koperasikita'); ?></span>
                        <input type="text" id="kk-template-header-bg" name="kk_template_header_bg" value="<?php echo esc_attr($headerBg); ?>" placeholder="<?php esc_attr_e('Palette default', 'koperasikita'); ?>" size="8" maxlength="7">
                    </label>
                    <label class="kk-data-desk-chart-color">
                        <span><?php esc_html_e('Text', 'koperasikita'); ?></span>
                        <input type="text" id="kk-template-header-text" name="kk_template_header_text" value="<?php echo esc_attr($headerText); ?>" placeholder="<?php esc_attr_e('Palette default', 'koperasikita'); ?>" size="8" maxlength="7">
                    </label>
                    <span class="description"><?php esc_html_e('Hex colour, e.g. #0B3D24. Empty uses the palette colours.', 'koperasikita'); ?></span>
                </div>
            </div>
            <?php /* The live preview IS the editor: the real panel /
             * table markup with sample data, re-rendered by admin.ts
             * (kk_data_desk_preview) whenever a top-bar field
             * changes. The server renders the first preview so the
             * section is never empty. */ ?>
            <?php /* Kind-aware hint: the panel preview has a title
             * to click, the table preview has none - its title is
             * edited in the top bar above, so the table hint must
             * never promise a clickable preview title. The toggle
             * script below shows the matching hint. */ ?>
            <p class="description kk-data-desk-editor-hint" data-kk-template-panel-hint>
                <?php esc_html_e("Click the title or 'Data Desk' in the preview to edit them. In the title you can use {label} for the symbol name and {day} for the number of days. Click the date line under the title to edit it in a popup. Click a stat block to edit it in a popup, or the eye button on a stat to hide it or show it again.", 'koperasikita'); ?>
            </p>
            <p class="description kk-data-desk-editor-hint" data-kk-template-table-hint>
                <?php esc_html_e('Edit the title in the top bar above. In the title you can use {label} for the first brand and {day} for the number of days. Click a column header in the preview to edit it.', 'koperasikita'); ?>
            </p>
            <div id="kk-data-desk-live-preview" data-kk-data-desk-preview>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview returns the theme's own template markup.
                // Editable: the first preview must carry the stat /
                // date-line edit hooks + eye toggles, not just the
                // AJAX refresh (which already passes true).
                echo DataDesk::renderPreview($template, true);
                ?>
            </div>
            <?php /* The placeholder tokens as a JSON blob: the
             * token tooling (right-click menu, { autocomplete,
             * Insert popup) reads them from here, not from a card
             * on the page - the reference itself lives in the
             * Help tab. */ ?>
            <script type="application/json" id="kk-data-desk-placeholder-tokens"><?php echo wp_json_encode(self::placeholderTokenRows($template)); ?></script>
        </form>
        <?php /* Placeholder settings popup: centered panel over a
         * blurred backdrop, like the list preview popup. The radios
         * are UI controls only (admin.ts writes the choice into the
         * hidden fields above); the popup itself never submits. */ ?>
        <div class="kk-data-desk-popup" id="kk-data-desk-placeholders-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-data-desk-placeholders-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-placeholder-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-data-desk-placeholders-popup-title"><?php esc_html_e('Placeholder settings', 'koperasikita'); ?></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button" data-kk-placeholder-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body">
                    <p class="description">
                        <?php esc_html_e('These settings change how placeholders resolve in the date line and the stat notes. They apply to the preview immediately and are saved with the template.', 'koperasikita'); ?>
                    </p>
                    <fieldset class="kk-data-desk-placeholder-group">
                        <legend><?php esc_html_e('Date format', 'koperasikita'); ?></legend>
                        <?php foreach (DataDesk::DATE_FORMATS as $value => $label) : ?>
                        <label class="kk-data-desk-placeholder-option">
                            <input type="radio" name="kk_placeholder_date_format_ui" value="<?php echo esc_attr($value); ?>" <?php checked($placeholders['date_format'], $value); ?>>
                            <?php echo esc_html(DataDesk::dateFormatLabel($value)); ?> &ndash; <?php echo esc_html(DataDesk::formatPlaceholderDate('2026-11-21', $value)); ?>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset class="kk-data-desk-placeholder-group">
                        <legend><?php esc_html_e('Percent type', 'koperasikita'); ?></legend>
                        <label class="kk-data-desk-placeholder-option">
                            <input type="radio" name="kk_placeholder_percent_ui" value="daily" <?php checked($placeholders['percent'], 'daily'); ?>>
                            <?php esc_html_e('Daily change (today vs yesterday)', 'koperasikita'); ?>
                        </label>
                        <label class="kk-data-desk-placeholder-option">
                            <input type="radio" name="kk_placeholder_percent_ui" value="period" <?php checked($placeholders['percent'], 'period'); ?>>
                            <?php esc_html_e('Whole period (first day to today)', 'koperasikita'); ?>
                        </label>
                    </fieldset>
                    <?php $zoneCustomColor = DataDesk::normalizeHexColor($placeholders['zone_color']); ?>
                    <fieldset class="kk-data-desk-placeholder-group">
                        <legend><?php esc_html_e('Zone colour', 'koperasikita'); ?></legend>
                        <?php foreach (DataDesk::ZONE_COLORS as $value => $label) : ?>
                        <label class="kk-data-desk-placeholder-option">
                            <input type="radio" name="kk_placeholder_zone_color_ui" value="<?php echo esc_attr($value); ?>" <?php checked($placeholders['zone_color'], $value); ?>>
                            <?php echo esc_html(DataDesk::zoneColorLabel($value)); ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="kk-data-desk-placeholder-option">
                            <input type="radio" name="kk_placeholder_zone_color_ui" value="custom" <?php checked($zoneCustomColor !== ''); ?>>
                            <?php esc_html_e('Custom', 'koperasikita'); ?>
                        </label>
                        <input type="color" id="kk-placeholder-zone-color-custom" value="<?php echo esc_attr($zoneCustomColor !== '' ? $zoneCustomColor : '#0b6b3a'); ?>" aria-label="<?php esc_attr_e('Custom zone colour', 'koperasikita'); ?>"<?php echo $zoneCustomColor !== '' ? '' : ' hidden'; ?>>
                    </fieldset>
                </div>
            </div>
        </div>
        <?php /* Insert popup: the help tab's Insert buttons open
         * this instead of inserting blindly. The body is filled
         * by admin.ts (token, resolved sample, target picker)
         * because the targets depend on the token and the
         * template kind. */ ?>
        <div class="kk-data-desk-popup" id="kk-data-desk-placeholders-insert-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-data-desk-placeholders-insert-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-placeholder-insert-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-data-desk-placeholders-insert-popup-title"><?php esc_html_e('Insert placeholder', 'koperasikita'); ?></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button" data-kk-placeholder-insert-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body" data-kk-placeholder-insert-popup-body></div>
            </div>
        </div>
        <?php /* Stat / date-line field popup: clicking a stat block
         * or the date line in the preview opens this instead of
         * editing inline. The fields are contenteditable divs
         * (admin.ts fills them with the RAW stored values), so
         * the token tooling works in them exactly as in the
         * title. Save writes the hidden fields in the form above
         * and refreshes the preview; Cancel discards. The percent
         * and zone selects edit the template's placeholder
         * settings, the same ones the settings popup edits. */ ?>
        <div class="kk-data-desk-popup" id="kk-data-desk-field-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-data-desk-field-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-field-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-data-desk-field-popup-title"></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button button-primary" data-kk-field-popup-save><?php esc_html_e('Save', 'koperasikita'); ?></button>
                        <button type="button" class="button" data-kk-field-popup-close><?php esc_html_e('Cancel', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body">
                    <div data-kk-field-popup-stat>
                        <p class="kk-data-desk-popup-field-row">
                            <span class="kk-data-desk-popup-field-label" id="kk-data-desk-field-popup-label-label"><?php esc_html_e('Label', 'koperasikita'); ?></span>
                            <span class="kk-data-desk-popup-field kk-data-desk-popup-field--label" data-kk-field-popup-label aria-labelledby="kk-data-desk-field-popup-label-label"></span>
                        </p>
                        <p class="kk-data-desk-popup-field-row">
                            <span class="kk-data-desk-popup-field-label" id="kk-data-desk-field-popup-sub-label"><?php esc_html_e('Note', 'koperasikita'); ?></span>
                            <span class="kk-data-desk-popup-field kk-data-desk-popup-field--sub" data-kk-field-popup-sub aria-labelledby="kk-data-desk-field-popup-sub-label"></span>
                        </p>
                        <p class="kk-data-desk-popup-field-row">
                            <label class="kk-data-desk-popup-field-label" for="kk-data-desk-field-popup-percent"><?php esc_html_e('Percent type', 'koperasikita'); ?></label>
                            <select id="kk-data-desk-field-popup-percent" data-kk-field-popup-percent>
                                <?php foreach (DataDesk::PERCENT_TYPES as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html(DataDesk::percentTypeLabel($value)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p class="kk-data-desk-popup-field-row">
                            <label class="kk-data-desk-popup-field-label" for="kk-data-desk-field-popup-zone"><?php esc_html_e('Zone colour', 'koperasikita'); ?></label>
                            <select id="kk-data-desk-field-popup-zone" data-kk-field-popup-zone>
                                <?php foreach (DataDesk::ZONE_COLORS as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html(DataDesk::zoneColorLabel($value)); ?></option>
                                <?php endforeach; ?>
                                <option value="custom"><?php esc_html_e('Custom', 'koperasikita'); ?></option>
                            </select>
                            <input type="color" id="kk-data-desk-field-popup-zone-custom" data-kk-field-popup-zone-custom value="#0b6b3a" aria-label="<?php esc_attr_e('Custom zone colour', 'koperasikita'); ?>" hidden>
                        </p>
                    </div>
                    <div data-kk-field-popup-date hidden>
                        <p class="kk-data-desk-popup-field-row">
                            <span class="kk-data-desk-popup-field-label" id="kk-data-desk-field-popup-date-line-label"><?php esc_html_e('Date line', 'koperasikita'); ?></span>
                            <span class="kk-data-desk-popup-field kk-data-desk-popup-field--date-line" data-kk-field-popup-date-line aria-labelledby="kk-data-desk-field-popup-date-line-label"></span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
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

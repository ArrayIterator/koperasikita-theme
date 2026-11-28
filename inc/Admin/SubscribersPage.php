<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Subscribe;
use KoperasiKita\Features\Subscribers;

/**
 * Koperasi Kita -> Subscribers: the theme's own newsletter
 * subscriber list.
 *
 * Every valid [kk_subscribe] submit is stored in the local
 * subscribers table first (Subscribers::record), whether MailPoet
 * is active or not, so this page is the record of who subscribed.
 * The list is a WP_List_Table (checkboxes, bulk delete, search,
 * sortable columns, pagination), like the Locations list.
 * Server-rendered with WP admin styles only - no custom CSS and
 * no admin.js. The CSV export runs through admin-post.php
 * (registered in Theme::boot) because the page callback itself
 * runs after the admin headers are already sent.
 */
final class SubscribersPage
{
    public const PAGE_SLUG = 'kk-subscribers';

    private const NONCE_EXPORT = 'kk_subscribers_export';

    private const NONCE_MAILPOET_SETTINGS = 'kk_mailpoet_settings';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deleted flag is display-only.
        $deleted = isset($_GET['deleted']);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetSettings = isset($_GET['mailpoet_settings']) && (string) wp_unslash($_GET['mailpoet_settings']) === '1';

        $table = new SubscribersTable();
        $table->prepare_items();

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $exportUrl = wp_nonce_url(
            admin_url('admin-post.php?action=kk_export_subscribers'),
            self::NONCE_EXPORT
        );
        ?>
        <div class="wrap kk-subscribers-page">
            <h1 class="kk-subscribers-title"><?php esc_html_e('Subscribers', 'koperasikita'); ?></h1>
            <?php if ($deleted) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscriber deleted.', 'koperasikita'); ?></p></div>
            <?php endif; ?>
            <?php if ($mailpoetSettings) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('MailPoet settings saved.', 'koperasikita'); ?></p></div>
            <?php endif; ?>
            <?php self::renderMailPoetSection(); ?>
            <section class="kk-subscribers-card">
            <p class="kk-subscribers-card-actions">
                <a class="button button-primary" href="<?php echo esc_url($exportUrl); ?>"><?php esc_html_e('Export CSV', 'koperasikita'); ?></a>
            </p>
            <form method="get" action="<?php echo esc_url($pageUrl); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <?php $table->search_box(__('Search Subscribers', 'koperasikita'), 'kk-subscriber'); ?>
                <?php $table->display(); ?>
            </form>
            </section>
        </div>
        <?php
    }

    /**
     * Handle the single / bulk delete submissions before any output,
     * so the redirect after a write can actually be sent. Runs on
     * admin_init, only on this page; the render only reads the
     * deleted flag.
     */
    public static function maybeHandleActions(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page is display-only.
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

        if ($page !== self::PAGE_SLUG) {
            return;
        }

        self::handleActions();
    }

    /**
     * Single delete (row action link) and bulk delete (Tindakan
     * Massal, top or bottom): nonce + capability checked, then
     * redirect back with a deleted flag so a refresh never
     * resubmits the form.
     */
    private static function handleActions(): void
    {
        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- single delete uses a nonce in the URL.
        if (isset($_GET['kk_subscriber_delete']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_subscriber_delete', '_wpnonce');

            $id = absint((string) wp_unslash($_GET['kk_subscriber_delete']));
            $removed = $id > 0 && Subscribers::delete($id) ? 1 : 0;

            wp_safe_redirect(add_query_arg('deleted', (string) max(1, $removed), $pageUrl));
            exit;
        }

        // Bulk delete from the list table: action=delete (top) or
        // action2=delete (bottom) + subscriber[] + the
        // bulk-subscribers nonce the table itself renders.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk delete uses the table nonce.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk delete uses the table nonce.
        $action2 = isset($_GET['action2']) ? sanitize_key((string) wp_unslash($_GET['action2'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk delete uses the table nonce.
        if (($action === 'delete' || $action2 === 'delete') && isset($_GET['subscriber']) && is_array($_GET['subscriber'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('bulk-subscribers');

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
            $ids = array_values(array_map(static fn($id): int => absint((string) $id), wp_unslash($_GET['subscriber'])));
            $removed = 0;

            foreach ($ids as $id) {
                if ($id > 0 && Subscribers::delete($id)) {
                    $removed++;
                }
            }

            wp_safe_redirect(add_query_arg('deleted', (string) max(1, $removed), $pageUrl));
            exit;
        }
    }

    /**
     * Streams the full subscriber list as a CSV download. Runs on
     * admin-post.php, before any output, so the download headers
     * can still be sent.
     */
    public static function handleExport(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer(self::NONCE_EXPORT);

        $rows = Subscribers::exportRows();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="subscribers-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, ['Email', 'First name', 'Last name', 'Date subscribed', 'Status', 'Source']);

        foreach ($rows as $row) {
            fputcsv($out, [$row['email'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status'], $row['source']]);
        }

        fclose($out);
        exit;
    }

    /**
     * The MailPoet card above the list: the plugin status, the
     * on/off setting for new signups, and the two-way sync with
     * MailPoet. Same card language as the Setup page - a head
     * row with the title and the sync action, then a body with
     * the description, the status line and the setting form -
     * and WP-default buttons only. The sync runs in an AJAX
     * popup (admin.ts initMailPoetSync), never a page reload,
     * so the button is a plain button, not a form. When
     * MailPoet is not active there is nothing to sync with, so
     * the button stays hidden and only the status line shows.
     */
    private static function renderMailPoetSection(): void
    {
        $status = Subscribe::mailPoetStatus();
        $syncEnabled = Subscribe::mailPoetSyncEnabled();
        $pending = $status === Subscribe::MAILPOET_ACTIVE ? Subscribers::countLocalPending() : 0;
        ?>
        <section class="kk-subscribers-card">
            <div class="kk-subscribers-card-head">
                <h2 class="kk-subscribers-card-title"><?php esc_html_e('MailPoet', 'koperasikita'); ?></h2>
                <?php if ($status === Subscribe::MAILPOET_ACTIVE) : ?>
                    <button type="button" class="button" data-kk-mailpoet-sync><?php esc_html_e('Sync With MailPoet', 'koperasikita'); ?></button>
                <?php endif; ?>
            </div>
            <div class="kk-subscribers-card-body">
                <?php if ($status === Subscribe::MAILPOET_ACTIVE) : ?>
                    <?php if ($pending > 0) : ?>
                        <p><?php
                            // translators: %d is the number of subscribers not in MailPoet yet.
                            printf(esc_html__('Subscribers stored here but not in MailPoet yet: %d.', 'koperasikita'), $pending);
                        ?></p>
                        <p><?php esc_html_e('Syncs subscribers with MailPoet in both directions.', 'koperasikita'); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e('All subscribers are already in MailPoet.', 'koperasikita'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p><?php esc_html_e('MailPoet is the newsletter plugin. When it is active, new subscribers can also be added to the MailPoet database, and the subscribers already stored here can be synced into it.', 'koperasikita'); ?></p>
                <p><strong><?php echo esc_html(self::mailPoetStatusLine($status)); ?></strong></p>
                <?php if ($status !== Subscribe::MAILPOET_INACTIVE) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-kk-mailpoet-settings>
                        <input type="hidden" name="action" value="kk_save_mailpoet_settings">
                        <?php wp_nonce_field(self::NONCE_MAILPOET_SETTINGS, 'kk_mailpoet_settings_nonce'); ?>
                        <p>
                            <label class="kk-subscribers-toggle">
                                <span><?php esc_html_e('Add new subscribers to the MailPoet database', 'koperasikita'); ?></span>
                                <span class="kk-subscribers-toggle-switch">
                                    <input type="checkbox" name="kk_mailpoet_sync" value="yes"<?php checked($syncEnabled); ?>>
                                    <span class="kk-subscribers-toggle-track" aria-hidden="true"></span>
                                </span>
                            </label>
                        </p>
                        <?php submit_button(__('Save MailPoet settings', 'koperasikita'), 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <?php self::renderMailPoetSyncPopup(); ?>
        <?php
    }

    /**
     * The two-way sync popup, same shape as the gold scrape
     * popup: one scrolling pre for the per-address lines, the
     * run summary once in a success notice below it, WP-default
     * buttons. The panel is filled by admin.ts
     * (initMailPoetSync); without JS the button does nothing
     * and the list stays usable. Closing reloads the page, so
     * the pending count and the list show the synced rows.
     */
    private static function renderMailPoetSyncPopup(): void
    {
        ?>
        <div class="kk-data-desk-popup" id="kk-mailpoet-sync-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-mailpoet-sync-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-mailpoet-sync-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-mailpoet-sync-popup-title"><?php esc_html_e('Sync With MailPoet', 'koperasikita'); ?></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button" data-kk-mailpoet-sync-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body">
                    <p><progress data-kk-mailpoet-sync-bar max="100" value="0" style="width: 100%; max-width: 30rem;"></progress></p>
                    <pre data-kk-mailpoet-sync-status role="status" aria-live="polite"></pre>
                    <div class="notice notice-success inline" data-kk-mailpoet-sync-result role="status" aria-live="polite" hidden></div>
                    <p class="description"><?php esc_html_e('Closing this popup reloads the page so the subscriber list and the pending count show the synced addresses.', 'koperasikita'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    private static function mailPoetStatusLine(string $status): string
    {
        if ($status === Subscribe::MAILPOET_NO_LISTS) {
            return __('MailPoet is active but has no lists yet. Create a list in MailPoet before syncing.', 'koperasikita');
        }

        if ($status === Subscribe::MAILPOET_ERROR) {
            return __('MailPoet is active but could not be reached. Please try again later.', 'koperasikita');
        }

        if ($status === Subscribe::MAILPOET_ACTIVE) {
            return __('MailPoet is active.', 'koperasikita');
        }

        return __('MailPoet is not installed or not active.', 'koperasikita');
    }

    /**
     * Save the MailPoet yes/no setting (admin-post.php): nonce +
     * capability checked, then redirect back with a saved flag so
     * a refresh never resubmits the form. The setting is a
     * checkbox toggle: checked posts 'yes', unchecked posts
     * nothing, which reads as 'no'.
     */
    public static function handleMailPoetSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer(self::NONCE_MAILPOET_SETTINGS, 'kk_mailpoet_settings_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed value below.
        $value = isset($_POST['kk_mailpoet_sync']) ? (string) wp_unslash($_POST['kk_mailpoet_sync']) : 'no';

        update_option(Subscribe::OPTION_MAILPOET_SYNC, $value === 'yes' ? 'yes' : 'no', false);

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);

        wp_safe_redirect(add_query_arg('mailpoet_settings', '1', $pageUrl));
        exit;
    }
}

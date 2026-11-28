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
 * Server-rendered with WP admin styles only - no custom CSS and
 * no admin.js, so it cannot be bitten by the submenu hook-suffix
 * trap. The CSV export runs through admin-post.php (registered in
 * Theme::boot) because the page callback itself runs after the
 * admin headers are already sent.
 */
final class SubscribersPage
{
    public const PAGE_SLUG = 'kk-subscribers';

    /** Rows per page in the list. */
    private const PER_PAGE = 20;

    private const NONCE_DELETE = 'kk_subscribers_delete';

    private const NONCE_EXPORT = 'kk_subscribers_export';

    private const NONCE_MAILPOET_SETTINGS = 'kk_mailpoet_settings';

    private const NONCE_MAILPOET_SYNC = 'kk_sync_subscribers_mailpoet';

    /** Addresses synced per MailPoet sync click. */
    private const MAILPOET_SYNC_BATCH = 100;

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search and paging are display-only.
        $search = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search and paging are display-only.
        $page = isset($_GET['paged']) ? max(1, absint((string) wp_unslash($_GET['paged']))) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
        $deleted = isset($_GET['deleted']) && (string) wp_unslash($_GET['deleted']) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetSettings = isset($_GET['mailpoet_settings']) && (string) wp_unslash($_GET['mailpoet_settings']) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetUnavailable = isset($_GET['mailpoet_unavailable']) && (string) wp_unslash($_GET['mailpoet_unavailable']) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetSynced = isset($_GET['mailpoet_synced']) ? absint((string) wp_unslash($_GET['mailpoet_synced'])) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetFailed = isset($_GET['mailpoet_failed']) ? absint((string) wp_unslash($_GET['mailpoet_failed'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags.
        $mailpoetRemaining = isset($_GET['mailpoet_remaining']) ? absint((string) wp_unslash($_GET['mailpoet_remaining'])) : 0;

        $result = Subscribers::list([
            'search' => $search,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
        $rows = $result['rows'];
        $total = $result['total'];
        $pages = (int) ceil($total / self::PER_PAGE);

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
            <?php if ($mailpoetSynced !== null) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    // translators: %d is a count of subscribers: synced, failed, remaining.
                    printf(esc_html__('Sync complete: %d synced, %d failed, %d remaining.', 'koperasikita'), $mailpoetSynced, $mailpoetFailed, $mailpoetRemaining);
                ?></p></div>
            <?php endif; ?>
            <?php if ($mailpoetUnavailable) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('MailPoet is not available, so nothing was synced.', 'koperasikita'); ?></p></div>
            <?php endif; ?>
            <?php self::renderMailPoetSection(); ?>
            <section class="kk-subscribers-card">
            <p><?php
                // translators: %d is the number of subscribers.
                printf(esc_html__('Total: %d subscribers', 'koperasikita'), (int) $total);
            ?></p>
            <form method="get" action="<?php echo esc_url($pageUrl); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <p class="search-box">
                    <label class="screen-reader-text" for="kk-subscribers-search"><?php esc_html_e('Search by email', 'koperasikita'); ?></label>
                    <input type="search" id="kk-subscribers-search" name="s" value="<?php echo esc_attr($search); ?>">
                    <input type="submit" class="button" value="<?php esc_attr_e('Search Subscribers', 'koperasikita'); ?>">
                </p>
            </form>
            <p class="kk-subscribers-card-actions">
                <a class="button button-primary" href="<?php echo esc_url($exportUrl); ?>"><?php esc_html_e('Export CSV', 'koperasikita'); ?></a>
            </p>
            <?php if ($rows === []) : ?>
                <p><?php esc_html_e('No subscribers yet.', 'koperasikita'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="column-primary"><?php esc_html_e('Email', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Date subscribed', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Source', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'koperasikita'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td class="column-primary" data-colname="<?php echo esc_attr(__('Email', 'koperasikita')); ?>"><?php echo esc_html($row['email']); ?></td>
                                <td data-colname="<?php echo esc_attr(__('Date subscribed', 'koperasikita')); ?>"><?php echo esc_html(self::formatDate($row['created_at'])); ?></td>
                                <td data-colname="<?php echo esc_attr(__('Status', 'koperasikita')); ?>"><?php echo esc_html(self::statusLabel($row['status'])); ?></td>
                                <td data-colname="<?php echo esc_attr(__('Source', 'koperasikita')); ?>"><?php echo esc_html(self::sourceLabel($row['source'])); ?></td>
                                <td data-colname="<?php echo esc_attr(__('Actions', 'koperasikita')); ?>">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="kk_delete_subscriber">
                                        <?php wp_nonce_field(self::NONCE_DELETE, 'kk_subscribers_delete_nonce'); ?>
                                        <input type="hidden" name="subscriber_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                        <input type="submit" class="button-link-delete kk-subscribers-delete" value="<?php esc_attr_e('Delete', 'koperasikita'); ?>" data-confirm="<?php esc_attr_e('Confirm delete?', 'koperasikita'); ?>">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($pages > 1) : ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                'base' => add_query_arg('paged', '%#%', $search !== '' ? add_query_arg('s', $search, $pageUrl) : $pageUrl),
                                'format' => '',
                                'total' => $pages,
                                'current' => $page,
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            </section>
        </div>
        <script>
        // Two-step delete: the first click arms the button
        // ("Confirm delete?"), the second submits. No native
        // confirm() dialog - some browsers and automation dismiss
        // those without a trace, which silently swallowed the
        // delete entirely. Without JS the button submits at once.
        (function () {
            var buttons = document.querySelectorAll('.kk-subscribers-delete');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function (ev) {
                    if (!btn.classList.contains('is-armed')) {
                        ev.preventDefault();
                        if (!btn.dataset.original) {
                            btn.dataset.original = btn.value;
                        }
                        btn.classList.add('is-armed');
                        btn.value = btn.getAttribute('data-confirm');
                    }
                });
                btn.addEventListener('blur', function () {
                    if (btn.classList.contains('is-armed')) {
                        btn.classList.remove('is-armed');
                        btn.value = btn.dataset.original || btn.value;
                    }
                });
            });
        })();
        </script>
        <?php
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

        fputcsv($out, ['Email', 'Date subscribed', 'Status', 'Source']);

        foreach ($rows as $row) {
            fputcsv($out, [$row['email'], $row['created_at'], $row['status'], $row['source']]);
        }

        fclose($out);
        exit;
    }

    /**
     * Delete one subscriber (admin-post.php): nonce + capability
     * checked, then redirect back with a deleted flag so a refresh
     * never resubmits the form. Runs on admin-post.php, before any
     * output - handling the POST inside render() would redirect
     * after the admin headers are already sent.
     */
    public static function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer(self::NONCE_DELETE, 'kk_subscribers_delete_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $id = isset($_POST['subscriber_id']) ? absint((string) wp_unslash($_POST['subscriber_id'])) : 0;

        if ($id > 0) {
            Subscribers::delete($id);
        }

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);

        wp_safe_redirect(add_query_arg('deleted', '1', $pageUrl));
        exit;
    }

    /**
     * The MailPoet card above the list: the plugin status, the
     * yes/no setting for new signups, and the one-click sync of
     * the subscribers stored here. Same card language as the
     * TrayDigita Stack card on the Setup page - title, description,
     * status line - and WP-default buttons only. When MailPoet is
     * not active there is nothing to sync into, so the forms stay
     * hidden and only the status line shows.
     */
    private static function renderMailPoetSection(): void
    {
        $status = Subscribe::mailPoetStatus();
        $syncEnabled = Subscribe::mailPoetSyncEnabled();
        ?>
        <section class="kk-subscribers-card">
            <h2 class="kk-subscribers-card-title"><?php esc_html_e('MailPoet', 'koperasikita'); ?></h2>
            <p><?php esc_html_e('MailPoet is the newsletter plugin. When it is active, new subscribers can also be added to the MailPoet database, and the subscribers already stored here can be synced into it.', 'koperasikita'); ?></p>
            <p><strong><?php echo esc_html(self::mailPoetStatusLine($status)); ?></strong></p>
            <?php if ($status !== Subscribe::MAILPOET_INACTIVE) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="kk_save_mailpoet_settings">
                    <?php wp_nonce_field(self::NONCE_MAILPOET_SETTINGS, 'kk_mailpoet_settings_nonce'); ?>
                    <p>
                        <span><?php esc_html_e('Add new subscribers to the MailPoet database', 'koperasikita'); ?></span>
                        <label><input type="radio" name="kk_mailpoet_sync" value="yes"<?php checked($syncEnabled); ?>> <?php esc_html_e('Yes', 'koperasikita'); ?></label>
                        <label><input type="radio" name="kk_mailpoet_sync" value="no"<?php checked(! $syncEnabled); ?>> <?php esc_html_e('No', 'koperasikita'); ?></label>
                    </p>
                    <?php submit_button(__('Save MailPoet settings', 'koperasikita'), 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <?php if ($status === Subscribe::MAILPOET_ACTIVE) : ?>
                <?php $pending = Subscribers::countLocalPending(); ?>
                <?php if ($pending > 0) : ?>
                    <p><?php
                        // translators: %d is the number of subscribers not in MailPoet yet.
                        printf(esc_html__('Subscribers stored here but not in MailPoet yet: %d.', 'koperasikita'), $pending);
                    ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="kk_sync_subscribers_mailpoet">
                        <?php wp_nonce_field(self::NONCE_MAILPOET_SYNC, 'kk_mailpoet_sync_nonce'); ?>
                        <p><?php esc_html_e('Copies the subscribers stored here into MailPoet.', 'koperasikita'); ?></p>
                        <?php submit_button(__('Sync subscribers to MailPoet', 'koperasikita'), 'secondary', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <p><?php esc_html_e('All subscribers are already in MailPoet.', 'koperasikita'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
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
     * a refresh never resubmits the form.
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

    /**
     * Sync the locally stored subscribers into MailPoet
     * (admin-post.php): one batch per click, capped so a very large
     * list cannot time out the request. Only subscribed rows with a
     * 'local' source are synced; a confirmed sync flips the row's
     * source to 'mailpoet'. Redirects back with the counts.
     */
    public static function handleMailPoetSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer(self::NONCE_MAILPOET_SYNC, 'kk_mailpoet_sync_nonce');

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);

        if (Subscribe::mailPoetStatus() !== Subscribe::MAILPOET_ACTIVE) {
            wp_safe_redirect(add_query_arg('mailpoet_unavailable', '1', $pageUrl));
            exit;
        }

        $listIds = Subscribe::mailPoetSyncLists();
        $emails = Subscribers::localSubscribedEmails(self::MAILPOET_SYNC_BATCH);
        $synced = 0;
        $failed = 0;

        foreach ($emails as $email) {
            if (
                Subscribe::syncEmailToMailPoetLists($email, $listIds) === 'ok'
                && Subscribers::setSource($email, Subscribers::SOURCE_MAILPOET)
            ) {
                $synced++;
            } else {
                $failed++;
            }
        }

        $remaining = Subscribers::countLocalPending();

        wp_safe_redirect(add_query_arg([
            'mailpoet_synced' => (string) $synced,
            'mailpoet_failed' => (string) $failed,
            'mailpoet_remaining' => (string) $remaining,
        ], $pageUrl));
        exit;
    }

    private static function formatDate(string $mysqlDate): string
    {
        $timestamp = strtotime($mysqlDate);

        if ($timestamp === false) {
            return $mysqlDate;
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private static function statusLabel(string $status): string
    {
        if ($status === Subscribers::STATUS_PENDING) {
            return __('Pending', 'koperasikita');
        }

        if ($status === Subscribers::STATUS_UNSUBSCRIBED) {
            return __('Unsubscribed', 'koperasikita');
        }

        return __('Subscribed', 'koperasikita');
    }

    private static function sourceLabel(string $source): string
    {
        if ($source === Subscribers::SOURCE_MAILPOET) {
            return 'MailPoet';
        }

        return __('Local', 'koperasikita');
    }
}

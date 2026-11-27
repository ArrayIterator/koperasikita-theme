<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Subscribers;

/**
 * Koperasi Kita -> Subscribers: the theme's own newsletter
 * subscriber list.
 *
 * Every valid [kk_subscribe] submit is stored in the local
 * subscribers table first (Subscribers::record), whether MailPoet
 * is active or not, so this page is the record of who subscribed.
 * Server-rendered with WP admin styles only — no custom CSS and
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
        <div class="wrap">
            <h1><?php esc_html_e('Subscribers', 'koperasikita'); ?></h1>
            <?php if ($deleted) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscriber deleted.', 'koperasikita'); ?></p></div>
            <?php endif; ?>
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
            <p>
                <a class="button" href="<?php echo esc_url($exportUrl); ?>"><?php esc_html_e('Export CSV', 'koperasikita'); ?></a>
            </p>
            <?php if ($rows === []) : ?>
                <p><?php esc_html_e('No subscribers yet.', 'koperasikita'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Email', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Date subscribed', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Source', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'koperasikita'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['email']); ?></td>
                                <td><?php echo esc_html(self::formatDate($row['created_at'])); ?></td>
                                <td><?php echo esc_html(self::statusLabel($row['status'])); ?></td>
                                <td><?php echo esc_html(self::sourceLabel($row['source'])); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="kk_delete_subscriber">
                                        <?php wp_nonce_field(self::NONCE_DELETE, 'kk_subscribers_delete_nonce'); ?>
                                        <input type="hidden" name="subscriber_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                        <input type="submit" class="button-link-delete" value="<?php esc_attr_e('Delete', 'koperasikita'); ?>" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this subscriber?', 'koperasikita')); ?>');">
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
        </div>
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
     * output — handling the POST inside render() would redirect
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

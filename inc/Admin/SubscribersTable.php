<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Subscribers;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Subscribers list: a post-type-style table over the local
 * wp_kk_subscribers rows - checkboxes with bulk delete, search,
 * sortable columns, pagination. Rows are keyed by their stable id,
 * so a delete link never shifts when another row is removed.
 */
final class SubscribersTable extends \WP_List_Table
{
    public const PER_PAGE = 20;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'subscriber',
            'plural' => 'subscribers',
            'ajax' => false,
        ]);
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'email' => __('Email', 'koperasikita'),
            'date' => __('Date subscribed', 'koperasikita'),
            'status' => __('Status', 'koperasikita'),
            'source' => __('Source', 'koperasikita'),
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    protected function get_sortable_columns(): array
    {
        return [
            'email' => ['email', false],
            'date' => ['date', true],
            'status' => ['status', false],
            'source' => ['source', false],
        ];
    }

    /** @return array<string, string> */
    protected function get_bulk_actions(): array
    {
        return [
            'delete' => __('Delete', 'koperasikita'),
        ];
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_cb($item): string
    {
        return sprintf(
            '<input type="checkbox" name="subscriber[]" value="%d" />',
            (int) $item['id']
        );
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_email($item): string
    {
        $pageUrl = admin_url('admin.php?page=' . SubscribersPage::PAGE_SLUG);
        $deleteUrl = wp_nonce_url(add_query_arg('kk_subscriber_delete', (string) $item['id'], $pageUrl), 'kk_subscriber_delete');

        $actions = [
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($deleteUrl),
                esc_js(__('Delete this subscriber?', 'koperasikita')),
                esc_html__('Delete', 'koperasikita')
            ),
        ];

        return sprintf(
            '<strong>%s</strong> %s',
            esc_html($item['email']),
            $this->row_actions($actions)
        );
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_date($item): string
    {
        return esc_html(self::formatDate($item['created_at']));
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_status($item): string
    {
        return esc_html(self::statusLabel($item['status']));
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_source($item): string
    {
        return esc_html(self::sourceLabel($item['source']));
    }

    /** @param array{id: int, email: string, status: string, source: string, created_at: string, updated_at: string} $item */
    protected function column_default($item, $columnName): string
    {
        return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '&mdash;';
    }

    public function no_items(): void
    {
        esc_html_e('No subscribers yet.', 'koperasikita');
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Search filters by email.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search is a read-only list filter.
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        // Sort: click a column header to sort the whole list.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) wp_unslash($_REQUEST['orderby'])) : 'date';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $order = isset($_REQUEST['order']) && strtolower((string) wp_unslash($_REQUEST['order'])) === 'asc' ? 'asc' : 'desc';

        $perPage = self::PER_PAGE;
        $currentPage = $this->get_pagenum();

        $result = Subscribers::list([
            'search' => $search,
            'page' => $currentPage,
            'per_page' => $perPage,
            'orderby' => $orderby,
            'order' => $order,
        ]);

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page' => $perPage,
        ]);

        $this->items = $result['rows'];
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

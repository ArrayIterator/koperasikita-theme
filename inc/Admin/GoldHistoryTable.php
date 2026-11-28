<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Gold;
use KoperasiKita\Providers\GoldPricesTable;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The History list: a post-type-style table over the stored gold
 * price rows - core search, sortable date / brand / prices,
 * paging. The brand + date filter lives in the page's own filter
 * form above the table; this table reads the same request, so the
 * filter, the search and the sort always agree. No checkbox
 * column: a history row has no bulk action - deleting rows would
 * destroy stored prices and exporting a selection would be a
 * second export path beside the page's Export CSV / Export All.
 * No pagination args: the page keeps its paginate_links block
 * below the table, so the page links stay exactly as they were.
 */
final class GoldHistoryTable extends \WP_List_Table
{
    public const PER_PAGE = 20;

    /** @var array<string, string> */
    private array $brands;

    private string $provider;

    /** @var array{symbol: string, from: string, to: string} */
    private array $filters;

    /** @var array<string, string> */
    private array $official;

    private bool $filtered;

    private int $totalItems = 0;

    private int $currentPage = 1;

    private string $search = '';

    private string $orderBy = 'date';

    private string $order = 'desc';

    /**
     * @param array{symbol: string, from: string, to: string} $filters
     * @param array<string, string> $official
     * @param array<string, string> $brands Slug => label.
     */
    public function __construct(string $provider, array $filters, array $official, array $brands, bool $filtered)
    {
        parent::__construct([
            'singular' => 'gold-price',
            'plural' => 'gold-prices',
            'ajax' => false,
        ]);

        $this->provider = $provider;
        $this->filters = $filters;
        $this->official = $official;
        $this->brands = $brands;
        $this->filtered = $filtered;
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'date' => __('Date', 'koperasikita'),
            'brand' => __('Brand', 'koperasikita'),
            'provider' => __('Provider', 'koperasikita'),
            'sell' => __('Sell (per gram)', 'koperasikita'),
            'buyback' => __('Buyback (per gram)', 'koperasikita'),
        ];
    }

    /** @return array<string, array{0: string, 1: bool, 2?: string, 3?: string, 4?: string}> */
    protected function get_sortable_columns(): array
    {
        return [
            'date' => ['date', true, '', '', 'desc'],
            'brand' => ['brand', false],
            'sell' => ['sell', false],
            'buyback' => ['buyback', false],
        ];
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_date($item): string
    {
        return esc_html((string) ($item['recorded_date'] ?? '-'));
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_brand($item): string
    {
        $symbol = (string) ($item['symbol'] ?? '');

        return '<strong>' . esc_html($this->brands[$symbol] ?? $symbol) . '</strong>';
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_provider($item): string
    {
        return esc_html(Gold::providerLabel((string) ($item['provider'] ?? '')));
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_sell($item): string
    {
        return esc_html(number_format_i18n((float) ($item['sell_price'] ?? 0), 0));
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_buyback($item): string
    {
        return isset($item['buyback_price']) && $item['buyback_price'] !== null
            ? esc_html(number_format_i18n((float) $item['buyback_price'], 0))
            : '-';
    }

    /** @param array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string, fetched_at: int} $item */
    protected function column_default($item, $columnName): string
    {
        return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '&mdash;';
    }

    public function no_items(): void
    {
        esc_html_e(
            $this->filtered || $this->search !== ''
                ? 'No prices match the selected filters.'
                : 'No prices stored yet. Run a sync to fill the table.',
            'koperasikita'
        );
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Search matches a brand slug, a provider id or a date.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search is a read-only list filter.
        $this->search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $orderBy = isset($_REQUEST['orderby']) ? sanitize_key((string) wp_unslash($_REQUEST['orderby'])) : 'date';
        if (!in_array($orderBy, ['date', 'brand', 'sell', 'buyback'], true)) {
            $orderBy = 'date';
        }
        $this->orderBy = $orderBy;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $this->order = isset($_REQUEST['order']) && strtolower((string) wp_unslash($_REQUEST['order'])) === 'asc' ? 'asc' : 'desc';

        $filters = $this->filters;
        $filters['search'] = $this->search;

        $total = GoldPricesTable::historyCount($this->provider, $filters, $this->official);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $this->totalItems = $total;
        $this->currentPage = min($this->get_pagenum(), $pages);

        $this->items = GoldPricesTable::historyFiltered(
            $this->provider,
            $filters,
            self::PER_PAGE,
            ($this->currentPage - 1) * self::PER_PAGE,
            $this->official,
            $this->orderBy,
            $this->order
        );
    }

    public function get_total_items(): int
    {
        return $this->totalItems;
    }

    public function get_current_page(): int
    {
        return $this->currentPage;
    }

    public function get_search(): string
    {
        return $this->search;
    }

    public function get_orderby(): string
    {
        return $this->orderBy;
    }

    public function get_order(): string
    {
        return $this->order;
    }
}

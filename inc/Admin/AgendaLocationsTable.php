<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Agenda;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Locations list: a post-type-style table over the stored
 * `kk_agenda_locations` rows - checkboxes with bulk delete, search,
 * sortable name, pagination. Rows are keyed by their stable id, so
 * an edit / delete link never shifts when another row is removed.
 */
final class AgendaLocationsTable extends \WP_List_Table
{
    public const PER_PAGE = 20;

    /** @var array<int, array{id: string, name: string, address: string, lat: float, lng: float, description: string}> */
    private array $locations;

    /**
     * @param array<int, array{id: string, name: string, address: string, lat: float, lng: float, description: string}> $locations
     */
    public function __construct(array $locations)
    {
        parent::__construct([
            'singular' => 'location',
            'plural' => 'locations',
            'ajax' => false,
        ]);

        $this->locations = $locations;
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'koperasikita'),
            'address' => __('Address', 'koperasikita'),
            'coordinates' => __('Coordinates', 'koperasikita'),
            'description' => __('Location description', 'koperasikita'),
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    protected function get_sortable_columns(): array
    {
        return [
            'name' => ['name', false],
        ];
    }

    /** @return array<string, string> */
    protected function get_bulk_actions(): array
    {
        return [
            'delete' => __('Delete', 'koperasikita'),
        ];
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_cb($item): string
    {
        return sprintf(
            '<input type="checkbox" name="location[]" value="%s" />',
            esc_attr($item['id'])
        );
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_name($item): string
    {
        $pageUrl = admin_url('admin.php?page=' . Agenda::LOCATIONS_PAGE_SLUG);
        $editUrl = add_query_arg(['action' => 'edit', 'id' => $item['id']], $pageUrl);
        $deleteUrl = wp_nonce_url(add_query_arg('kk_location_delete', $item['id'], $pageUrl), 'kk_location_delete');

        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($editUrl), esc_html__('Edit', 'koperasikita')),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($deleteUrl),
                esc_js(__('Delete this location?', 'koperasikita')),
                esc_html__('Delete', 'koperasikita')
            ),
        ];

        return sprintf(
            '<strong><a href="%s">%s</a></strong> %s',
            esc_url($editUrl),
            esc_html($item['name']),
            $this->row_actions($actions)
        );
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_address($item): string
    {
        return $item['address'] !== '' ? esc_html($item['address']) : '&mdash;';
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_coordinates($item): string
    {
        return esc_html(number_format_i18n((float) $item['lat'], 6) . ', ' . number_format_i18n((float) $item['lng'], 6));
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_description($item): string
    {
        return $item['description'] !== '' ? esc_html($item['description']) : '&mdash;';
    }

    /** @param array{id: string, name: string, address: string, lat: float, lng: float, description: string} $item */
    protected function column_default($item, $columnName): string
    {
        return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '&mdash;';
    }

    public function no_items(): void
    {
        esc_html_e('No saved locations yet.', 'koperasikita');
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $items = $this->locations;

        // Search filters by name or address.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search is a read-only list filter.
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower($row['name']), $needle)
                    || str_contains(mb_strtolower($row['address']), $needle);
            }));
        }

        // Sort by name (default A-Z).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) wp_unslash($_REQUEST['orderby'])) : 'name';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $order = isset($_REQUEST['order']) && strtolower((string) wp_unslash($_REQUEST['order'])) === 'desc' ? 'desc' : 'asc';
        if ($orderby === 'name') {
            usort($items, static function (array $a, array $b) use ($order): int {
                $cmp = strnatcasecmp($a['name'], $b['name']);

                return $order === 'desc' ? -$cmp : $cmp;
            });
        }

        $perPage = self::PER_PAGE;
        $total = count($items);
        $currentPage = $this->get_pagenum();
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $perPage,
        ]);

        $this->items = array_slice($items, ($currentPage - 1) * $perPage, $perPage);
    }
}

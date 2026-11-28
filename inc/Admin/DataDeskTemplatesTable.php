<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\DataDesk;
use KoperasiKita\Features\Gold;
use KoperasiKita\Icons\Icon;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Data Desk templates list: a post-type-style table over the
 * stored templates - checkboxes with bulk duplicate, search,
 * sortable name/kind/shows, pagination. Rows are keyed by template id: the
 * built-ins (default, Antam 7-day) always come first, then user
 * templates in creation order, so a row never shifts when another
 * template is removed. The built-ins carry a lock badge and offer
 * Use (duplicate into your own template), Edit (saving stores an
 * override) and Restore (only while an override exists); user
 * templates offer Edit, Duplicate, Delete and Preview.
 */
final class DataDeskTemplatesTable extends \WP_List_Table
{
    public const PER_PAGE = 20;

    /**
     * @var array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string}>
     */
    private array $templates;

    private string $pageUrl;

    /**
     * @param array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string}> $templates
     */
    public function __construct(array $templates, string $pageUrl)
    {
        parent::__construct([
            'singular' => 'template',
            'plural' => 'templates',
            'ajax' => false,
        ]);

        $this->templates = $templates;
        $this->pageUrl = $pageUrl;
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'koperasikita'),
            'kind' => __('Kind', 'koperasikita'),
            'shows' => __('Shows', 'koperasikita'),
            'palette' => __('Palette', 'koperasikita'),
            'days' => __('Days', 'koperasikita'),
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    protected function get_sortable_columns(): array
    {
        return [
            'name' => ['name', false],
            'kind' => ['kind', false],
            'shows' => ['shows', false],
        ];
    }

    /** @return array<string, string> */
    protected function get_bulk_actions(): array
    {
        return [
            'duplicate' => __('Duplicate', 'koperasikita'),
        ];
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_cb($item): string
    {
        if (DataDesk::isBuiltIn($item['id'])) {
            return '';
        }

        return sprintf(
            '<input type="checkbox" name="template[]" value="%s" />',
            esc_attr($item['id'])
        );
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_name($item): string
    {
        $name = '<strong>' . esc_html($item['name']) . '</strong>';

        if (DataDesk::isBuiltIn($item['id'])) {
            $builtInLabel = __('Built-in', 'koperasikita');
            $badge = '<span class="kk-data-desk-built-in">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon::svg returns the theme's own SVG markup.
            $badge .= Icon::svg('lock', 13);

            if (strcasecmp($item['name'], $builtInLabel) !== 0) {
                $badge .= esc_html($builtInLabel);
            }

            $badge .= '</span>';
            $name .= ' ' . $badge;
        }

        return $name . $this->row_actions($this->actionLinks($item));
    }

    /**
     * Row actions. The array keys are the span classes WP_List_Table
     * wraps each link in, so the Delete action must be keyed 'delete':
     * core's red-delete rule (.row-actions span.delete a) only matches
     * that class. Numerically indexed arrays give the spans classes
     * "0"/"1" and the link stays blue.
     *
     * @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item @return array<string, string>
     */
    private function actionLinks(array $item): array
    {
        $id = $item['id'];
        $pageUrl = $this->pageUrl;

        if (DataDesk::isBuiltIn($id)) {
            $links = [
                'use' => '<a href="' . esc_url(wp_nonce_url(add_query_arg('kk_data_desk_duplicate', $id, $pageUrl), 'kk_data_desk_duplicate')) . '">' . esc_html__('Use', 'koperasikita') . '</a>',
                'edit' => '<a href="' . esc_url(add_query_arg(['action' => 'edit', 'id' => $id], $pageUrl)) . '">' . esc_html__('Edit', 'koperasikita') . '</a>',
            ];

            if (DataDesk::isOverridden($id)) {
                $links['restore'] = '<a href="' . esc_url(wp_nonce_url(add_query_arg('kk_data_desk_restore', $id, $pageUrl), 'kk_data_desk_restore')) . '" onclick="return confirm(\'' . esc_js(__('Restore this template to its built-in values?', 'koperasikita')) . '\');">' . esc_html__('Restore', 'koperasikita') . '</a>';
            }

            $links['preview'] = '<a href="#" data-kk-data-desk-preview-link data-kk-template-id="' . esc_attr($id) . '" data-kk-template-name="' . esc_attr($item['name']) . '">' . esc_html__('Preview', 'koperasikita') . '</a>';

            return $links;
        }

        return [
            'edit' => '<a href="' . esc_url(add_query_arg(['action' => 'edit', 'id' => $id], $pageUrl)) . '">' . esc_html__('Edit', 'koperasikita') . '</a>',
            'duplicate' => '<a href="' . esc_url(wp_nonce_url(add_query_arg('kk_data_desk_duplicate', $id, $pageUrl), 'kk_data_desk_duplicate')) . '">' . esc_html__('Duplicate', 'koperasikita') . '</a>',
            'delete' => '<a href="' . esc_url(wp_nonce_url(add_query_arg('kk_data_desk_delete', $id, $pageUrl), 'kk_data_desk_delete')) . '" onclick="return confirm(\'' . esc_js(__('Delete this template?', 'koperasikita')) . '\');">' . esc_html__('Delete', 'koperasikita') . '</a>',
            'preview' => '<a href="#" data-kk-data-desk-preview-link data-kk-template-id="' . esc_attr($id) . '" data-kk-template-name="' . esc_attr($item['name']) . '">' . esc_html__('Preview', 'koperasikita') . '</a>',
        ];
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_kind($item): string
    {
        $kinds = DataDesk::kinds();

        return esc_html($kinds[$item['kind']] ?? $item['kind']);
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_shows($item): string
    {
        return esc_html($this->showsLabel($item));
    }

    /** The unescaped text the Shows column renders; also the sort key for that column. */
    private function showsLabel(array $item): string
    {
        if ($item['kind'] === DataDesk::KIND_TABLE) {
            if ($item['brands'] !== []) {
                $brands = Gold::brands();

                return implode(', ', array_map(static fn(string $slug): string => $brands[$slug] ?? $slug, $item['brands']));
            }

            return __('All brands', 'koperasikita');
        }

        $symbols = DataDesk::symbols();

        return $symbols[$item['symbol']] ?? $item['symbol'];
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_palette($item): string
    {
        return esc_html(DataDesk::paletteLabel($item['palette']));
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_days($item): string
    {
        if ($item['kind'] === DataDesk::KIND_PANEL || ($item['kind'] === DataDesk::KIND_TABLE && ($item['table_style'] ?? '') === 'chart')) {
            return esc_html((string) $item['days']);
        }

        return '&mdash;';
    }

    /** @param array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, table_style: string} $item */
    protected function column_default($item, $columnName): string
    {
        return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '&mdash;';
    }

    public function no_items(): void
    {
        esc_html_e('No templates yet.', 'koperasikita');
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $items = array_values($this->templates);

        // Search filters by name.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- search is a read-only list filter.
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower($row['name']), $needle);
            }));
        }

        // Sort when requested; otherwise the stored order (built-ins
        // first, then user templates in creation order). The kind sort
        // uses the rendered kind label and the shows sort uses the
        // rendered brands/symbol text, so rows order as displayed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) wp_unslash($_REQUEST['orderby'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sorting is a read-only list filter.
        $order = isset($_REQUEST['order']) && strtolower((string) wp_unslash($_REQUEST['order'])) === 'desc' ? 'desc' : 'asc';
        if ($orderby === 'name') {
            usort($items, static function (array $a, array $b) use ($order): int {
                $cmp = strnatcasecmp($a['name'], $b['name']);

                return $order === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($orderby === 'kind') {
            $kinds = DataDesk::kinds();
            usort($items, static function (array $a, array $b) use ($order, $kinds): int {
                $cmp = strnatcasecmp($kinds[$a['kind']] ?? $a['kind'], $kinds[$b['kind']] ?? $b['kind']);

                return $order === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($orderby === 'shows') {
            usort($items, function (array $a, array $b) use ($order): int {
                $cmp = strnatcasecmp($this->showsLabel($a), $this->showsLabel($b));

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

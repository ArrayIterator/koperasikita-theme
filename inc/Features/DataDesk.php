<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Providers\GoldPricesTable;
use KoperasiKita\Providers\LogamMuliaApiProvider;

/**
 * Data Desk panel data: the chart + three stats for one market symbol.
 *
 * A Data Desk panel shows one symbol — a gold brand (Antam, UBS, ...)
 * or a stock (IHSG, LQ45) — over the last N days: a line chart of the
 * daily price, the current price with its day change, the buyback
 * price with its spread (gold only), and the weekly performance with
 * its up-streak. Everything is computed from real stored data; a
 * symbol with no usable history gets a placeholder chart and honest
 * empty stats instead of invented numbers.
 *
 * Gold history comes from the Logam Mulia price table (one row per
 * brand per day, with sell + buyback). Stocks have no history table,
 * so their chart is always a placeholder and their buyback stat is
 * empty — the current price and day change still come from the
 * Markets provider (Yahoo).
 */
final class DataDesk
{
    /** Option holding the custom Data Desk templates (id => template). */
    public const TEMPLATES_OPTION = 'kk_data_desk_templates';

    /** The built-in default template id. It is code, not a stored row, and can never be deleted or edited. */
    public const DEFAULT_TEMPLATE_ID = 'default';

    /** The built-in default table template id. Same protection as the panel default. */
    public const DEFAULT_TABLE_TEMPLATE_ID = 'default-table';

    public const KIND_PANEL = 'panel';
    public const KIND_TABLE = 'table';

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [
            self::KIND_PANEL => __('Panel', 'koperasikita'),
            self::KIND_TABLE => __('Table', 'koperasikita'),
        ];
    }

    public static function sanitizeKind(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return isset(self::kinds()[$value]) ? $value : self::KIND_PANEL;
    }

    /** Palettes: id => label shown in the template form. */
    public const PALETTES = [
        'dark-green' => 'Dark Green',
        'light' => 'Light',
        'dark' => 'Dark',
    ];

    public const DEFAULT_PALETTE = 'dark-green';

    public const DEFAULT_DAYS = 7;

    /**
     * The built-in default template (a panel). It is defined in code
     * and can never be deleted or edited — to change it, duplicate it
     * into a new template and edit that one instead.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}
     */
    public static function defaultTemplate(): array
    {
        return [
            'id' => self::DEFAULT_TEMPLATE_ID,
            'name' => __('Default', 'koperasikita'),
            'kind' => self::KIND_PANEL,
            'symbol' => 'gold:antam',
            'brands' => [],
            'palette' => self::DEFAULT_PALETTE,
            'days' => self::DEFAULT_DAYS,
            'title' => '',
        ];
    }

    /**
     * The built-in default table template (the Gold Data Desk table).
     * Same protection as the panel default: code, never deletable.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}
     */
    public static function defaultTableTemplate(): array
    {
        return [
            'id' => self::DEFAULT_TABLE_TEMPLATE_ID,
            'name' => __('Default Table', 'koperasikita'),
            'kind' => self::KIND_TABLE,
            'symbol' => 'gold:antam',
            'brands' => [],
            'palette' => self::DEFAULT_PALETTE,
            'days' => self::DEFAULT_DAYS,
            'title' => '',
        ];
    }

    /**
     * All templates: the two built-in defaults first, then the stored
     * custom templates in the order they were created.
     *
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}>
     */
    public static function templates(): array
    {
        $templates = [
            self::DEFAULT_TEMPLATE_ID => self::defaultTemplate(),
            self::DEFAULT_TABLE_TEMPLATE_ID => self::defaultTableTemplate(),
        ];
        $stored = get_option(self::TEMPLATES_OPTION, []);

        if (!is_array($stored)) {
            return $templates;
        }

        foreach ($stored as $id => $row) {
            if (!is_string($id) || $id === '' || $id === self::DEFAULT_TEMPLATE_ID || $id === self::DEFAULT_TABLE_TEMPLATE_ID || !is_array($row)) {
                continue;
            }

            $templates[$id] = self::sanitizeTemplate($id, $row);
        }

        return $templates;
    }

    /**
     * Templates of one kind (panel or table), in the same order as
     * templates(). The widget pickers use this so a panel widget
     * never offers a table template and vice versa.
     *
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}>
     */
    public static function templatesOfKind(string $kind): array
    {
        $kind = self::sanitizeKind($kind);

        return array_filter(
            self::templates(),
            static fn(array $template): bool => $template['kind'] === $kind
        );
    }

    /**
     * One template by id. An unknown id falls back to the default, so
     * a widget pointing at a deleted template keeps rendering.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}
     */
    public static function getTemplate(string $id): array
    {
        $templates = self::templates();

        return $templates[$id] ?? self::defaultTemplate();
    }

    /**
     * Save a custom template. The built-in defaults can never be
     * overwritten — saving with their id creates a new template
     * instead. Returns the id the template was saved under.
     *
     * @param array<string, mixed> $data
     */
    public static function saveTemplate(string $id, array $data): string
    {
        $stored = get_option(self::TEMPLATES_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        // A default id ('' or a built-in) can never be overwritten:
        // saving one mints a fresh id. An existing custom id is
        // updated in place.
        if ($isDefault) {
            $id = 'tpl_' . wp_generate_uuid4();
        }

        $stored[$id] = self::sanitizeTemplate($id, $data);
        update_option(self::TEMPLATES_OPTION, $stored);

        return $id;
    }

    /**
     * Delete a custom template. The built-in defaults can never be
     * deleted. Returns true when a template was removed.
     */
    public static function deleteTemplate(string $id): bool
    {
        if ($id === self::DEFAULT_TEMPLATE_ID || $id === self::DEFAULT_TABLE_TEMPLATE_ID) {
            return false;
        }

        $stored = get_option(self::TEMPLATES_OPTION, []);

        if (!is_array($stored) || !isset($stored[$id])) {
            return false;
        }

        unset($stored[$id]);
        update_option(self::TEMPLATES_OPTION, $stored);

        return true;
    }

    /**
     * Clean one template row into its canonical shape. An unknown
     * symbol falls back to gold:antam so a template never points at
     * a symbol that no longer exists; brands are intersected with
     * the enabled brands so a disabled brand never renders.
     *
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}
     */
    private static function sanitizeTemplate(string $id, array $row): array
    {
        $symbol = isset($row['symbol']) ? (string) $row['symbol'] : 'gold:antam';
        $brands = isset($row['brands']) && is_array($row['brands'])
            ? array_values(array_intersect(array_map('sanitize_key', $row['brands']), Gold::enabledBrands()))
            : [];

        return [
            'id' => $id,
            'name' => isset($row['name']) && trim((string) $row['name']) !== '' ? sanitize_text_field((string) $row['name']) : __('Untitled template', 'koperasikita'),
            'kind' => self::sanitizeKind($row['kind'] ?? self::KIND_PANEL),
            'symbol' => self::parseSymbol($symbol) !== null ? $symbol : 'gold:antam',
            'brands' => $brands,
            'palette' => self::sanitizePalette(isset($row['palette']) ? (string) $row['palette'] : self::DEFAULT_PALETTE),
            'days' => isset($row['days']) ? max(3, min(30, (int) $row['days'])) : self::DEFAULT_DAYS,
            'title' => isset($row['title']) ? sanitize_text_field((string) $row['title']) : '',
        ];
    }

    /**
     * The symbols a Data Desk panel can show, as "market:symbol" =>
     * label. Gold brands first, then stocks. Crypto is left out on
     * purpose — the panel is a gold / index desk, not a coin ticker.
     *
     * @return array<string, string>
     */
    public static function symbols(): array
    {
        $out = [];

        foreach (Gold::brands() as $slug => $label) {
            $out['gold:' . $slug] = $label;
        }

        foreach (Markets::symbols('stock') as $slug => $label) {
            $out['stock:' . $slug] = $label;
        }

        return $out;
    }

    public static function sanitizePalette(string $value): string
    {
        return isset(self::PALETTES[$value]) ? $value : self::DEFAULT_PALETTE;
    }

    /**
     * Split a "market:symbol" value. Returns null for anything that
     * is not a known market + symbol pair.
     *
     * @return array{market: string, symbol: string}|null
     */
    public static function parseSymbol(string $value): ?array
    {
        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$market, $symbol] = $parts;

        if ($market === 'gold') {
            if (!isset(Gold::brands()[$symbol])) {
                return null;
            }
        } elseif ($market === 'stock') {
            if (!isset(Markets::symbols('stock')[$symbol])) {
                return null;
            }
        } else {
            return null;
        }

        return ['market' => $market, 'symbol' => $symbol];
    }

    /**
     * The panel data for one symbol over the last N days.
     *
     * @return array{market: string, symbol: string, label: string, market_label: string, price: float|null, currency: string, change: float|null, change_abs: float|null, buyback: float|null, spread: float|null, week_change: float|null, streak: int, points: array<int, array{date: string, short: string, price: float}>, has_history: bool, date_range: string}
     */
    public static function panelData(string $market, string $symbol, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(3, min(30, $days));
        $labels = $market === 'gold' ? Gold::brands() : Markets::symbols('stock');
        $label = $labels[$symbol] ?? $symbol;
        $marketLabel = $market === 'gold' ? __('Gold', 'koperasikita') : __('Stocks', 'koperasikita');

        $data = [
            'market' => $market,
            'symbol' => $symbol,
            'label' => $label,
            'market_label' => $marketLabel,
            'price' => null,
            'currency' => 'IDR',
            'change' => null,
            'change_abs' => null,
            'buyback' => null,
            'spread' => null,
            'week_change' => null,
            'streak' => 0,
            'points' => [],
            'has_history' => false,
            'date_range' => '',
        ];

        // Current price + day change from the Markets snapshot layer.
        $prices = Markets::prices($market);
        $current = $prices[$symbol] ?? null;

        if ($current !== null && $current->hasData()) {
            $data['price'] = (float) $current->price;
            $data['currency'] = $current->currency;
            $data['change'] = $current->change;
        }

        if ($market !== 'gold') {
            // Stocks have no history table: the chart is a placeholder
            // and the buyback / weekly stats stay empty. The current
            // price and day change above are still real.
            return $data;
        }

        // Gold: the last N days of history for this brand, oldest first.
        $rows = [];
        foreach (GoldPricesTable::history(LogamMuliaApiProvider::PROVIDER_ID, 400) as $row) {
            if ((string) ($row['symbol'] ?? '') !== $symbol) {
                continue;
            }

            $date = (string) ($row['recorded_date'] ?? '');
            $sell = (float) ($row['sell_price'] ?? 0);

            if ($date === '' || $sell <= 0) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'price' => $sell,
                'buyback' => isset($row['buyback_price']) && $row['buyback_price'] !== null
                    ? (float) $row['buyback_price']
                    : null,
            ];
        }

        // Newest first in the table, so reverse to oldest first and
        // keep only the last N days.
        $rows = array_reverse($rows);
        $rows = array_slice($rows, -$days);

        if ($rows === []) {
            return $data;
        }

        $data['has_history'] = true;
        $data['points'] = array_map(static fn(array $row): array => [
            'date' => $row['date'],
            'short' => date_i18n('D d', strtotime($row['date'])),
            'price' => $row['price'],
        ], $rows);

        $first = $rows[0];
        $last = $rows[count($rows) - 1];
        $data['date_range'] = $first['date'] === $last['date']
            ? date_i18n(get_option('date_format'), strtotime($first['date']))
            : date_i18n('j F Y', strtotime($first['date'])) . ' – ' . date_i18n('j F Y', strtotime($last['date']));

        // Current price prefers the latest history row (it carries the
        // buyback too); the Markets snapshot is the fallback.
        $data['price'] = (float) $last['price'];
        $data['buyback'] = $last['buyback'];

        if ($data['buyback'] !== null && $data['price'] > 0) {
            $data['spread'] = (($data['price'] - $data['buyback']) / $data['price']) * 100;
        }

        // Day change: latest vs the previous day. Weekly change:
        // latest vs the first day in the window.
        $count = count($rows);

        if ($count >= 2) {
            $prev = (float) $rows[$count - 2]['price'];

            if ($prev > 0) {
                $data['change_abs'] = $data['price'] - $prev;
                $data['change'] = ($data['change_abs'] / $prev) * 100;
            }
        }

        if ($count >= 2) {
            $old = (float) $first['price'];

            if ($old > 0) {
                $data['week_change'] = (($data['price'] - $old) / $old) * 100;
            }
        }

        // Up-streak: trailing days the price rose vs the day before.
        $streak = 0;
        for ($i = $count - 1; $i >= 1; $i--) {
            if ((float) $rows[$i]['price'] > (float) $rows[$i - 1]['price']) {
                $streak++;
            } else {
                break;
            }
        }
        $data['streak'] = $streak;

        return $data;
    }
}

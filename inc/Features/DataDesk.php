<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Providers\GoldPricesTable;
use KoperasiKita\Providers\LogamMuliaApiProvider;
use KoperasiKita\Providers\MarketPrice;

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
        'auto' => 'Follow site theme',
    ];

    public const DEFAULT_PALETTE = 'dark-green';

    public const DEFAULT_DAYS = 7;

    /**
     * Register the admin preview endpoint. The templates page and
     * the widget forms re-render their preview through it, so the
     * preview always uses the real panel / table templates.
     */
    public static function register(): void
    {
        Ajax::add('kk_data_desk_preview', [self::class, 'ajaxPreview'], false, 'manage_options');
        Ajax::add('kk_data_desk_save_template', [self::class, 'ajaxSaveTemplate'], false, 'manage_options');
    }

    /**
     * Replace {label} / {day} placeholders in a Data Desk template title.
     *
     * {label} is the symbol label (e.g. "Antam"), {day} is the chart
     * window in days — the two values the automatic title is built
     * from. Unknown placeholders are left untouched, so a typo never
     * eats text. NOTE: this {day} is NOT the footer's {day} (day of
     * the month) — the two token systems must not be "unified".
     */
    public static function replaceTitleTokens(string $title, string $label, int $days): string
    {
        return strtr($title, [
            '{label}' => $label,
            '{day}'   => (string) $days,
        ]);
    }

    /**
     * The preview for one template (panel or table), rendered
     * through the real template files with clearly-labelled SAMPLE
     * data — never real prices presented as live. The templates
     * page list, the template form and the widget forms all use
     * this one path, so the preview is always the layout the
     * widget will actually render.
     */
    public static function renderPreview(array $template): string
    {
        ob_start();

        if (($template['kind'] ?? self::KIND_PANEL) === self::KIND_TABLE) {
            get_template_part('template-parts/gold/data-desk', null, [
                'brands' => self::previewTablePrices($template),
                'client' => [],
                'palette' => $template['palette'] ?? self::DEFAULT_PALETTE,
                'preview' => true,
            ]);
        } else {
            get_template_part('template-parts/data-desk/panel', null, [
                'data' => self::previewData($template),
                'palette' => $template['palette'] ?? self::DEFAULT_PALETTE,
                'days' => (int) ($template['days'] ?? self::DEFAULT_DAYS),
                'title' => $template['title'] ?? '',
                'kicker' => $template['kicker'] ?? '',
                'preview' => true,
            ]);
        }

        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * Sample panel data for a template: the same shape as
     * panelData(), but with a fixed, clearly-labelled sample series
     * (a gentle upward slope around a round base price) instead of
     * real history. The chart, the stats and the streak all render,
     * so the preview shows the layout — the numbers are illustrative
     * only and the template marks them as a preview.
     *
     * @return array{market: string, symbol: string, label: string, market_label: string, price: float|null, currency: string, change: float|null, change_abs: float|null, buyback: float|null, spread: float|null, week_change: float|null, streak: int, points: array<int, array{date: string, short: string, price: float}>, has_history: bool, date_range: string}
     */
    public static function previewData(array $template): array
    {
        $parsed = self::parseSymbol((string) ($template['symbol'] ?? 'gold:antam'));
        $parsed = $parsed ?? ['market' => 'gold', 'symbol' => 'antam'];
        $days = isset($template['days']) ? max(3, min(30, (int) $template['days'])) : self::DEFAULT_DAYS;
        $labels = $parsed['market'] === 'gold' ? Gold::brands() : Markets::symbols('stock');
        $label = $labels[$parsed['symbol']] ?? $parsed['symbol'];
        // Round sample bases, deliberately not a live price.
        $base = $parsed['market'] === 'gold' ? 2400000.0 : 5200.0;

        $points = [];

        for ($i = 0; $i < $days; $i++) {
            // A gentle upward slope with a small alternating wobble,
            // so the chart reads as a chart and the streak is real
            // (computed from these points, like the live path).
            $offset = -0.8 + (1.7 * $i) / max(1, $days - 1) + ($i % 2 === 0 ? 0.15 : -0.15);
            $date = date('Y-m-d', strtotime('-' . ($days - 1 - $i) . ' days'));
            $points[] = [
                'date' => $date,
                'short' => date_i18n('D d', strtotime($date)),
                'price' => round($base * (1 + $offset / 100)),
            ];
        }

        $first = $points[0];
        $last = $points[count($points) - 1];
        $prev = $points[count($points) - 2];
        $price = (float) $last['price'];
        $buyback = $parsed['market'] === 'gold' ? round($price * 0.95) : null;

        $streak = 0;
        for ($i = count($points) - 1; $i >= 1; $i--) {
            if ((float) $points[$i]['price'] > (float) $points[$i - 1]['price']) {
                $streak++;
            } else {
                break;
            }
        }

        return [
            'market' => $parsed['market'],
            'symbol' => $parsed['symbol'],
            'label' => $label,
            'market_label' => $parsed['market'] === 'gold' ? __('Gold', 'koperasikita') : __('Stocks', 'koperasikita'),
            'price' => $price,
            'currency' => 'IDR',
            'change' => $prev['price'] > 0 ? (($price - (float) $prev['price']) / (float) $prev['price']) * 100 : null,
            'change_abs' => $price - (float) $prev['price'],
            'buyback' => $buyback,
            'spread' => $buyback !== null && $price > 0 ? (($price - $buyback) / $price) * 100 : null,
            'week_change' => (float) $first['price'] > 0 ? (($price - (float) $first['price']) / (float) $first['price']) * 100 : null,
            'streak' => $streak,
            'points' => $points,
            'has_history' => true,
            'date_range' => $first['date'] === $last['date']
                ? date_i18n(get_option('date_format'), strtotime($first['date']))
                : date_i18n('j F Y', strtotime($first['date'])) . ' – ' . date_i18n('j F Y', strtotime($last['date'])),
        ];
    }

    /**
     * Sample table rows for a template: one MarketPrice per brand
     * the template shows (empty = all enabled brands), with fixed
     * round sample prices — never a live snapshot. The table
     * template marks them as a preview.
     *
     * @return array<string, MarketPrice>
     */
    public static function previewTablePrices(array $template): array
    {
        $brands = isset($template['brands']) && is_array($template['brands']) && $template['brands'] !== []
            ? array_values(array_intersect(array_map('sanitize_key', $template['brands']), Gold::enabledBrands()))
            : Gold::enabledBrands();

        $rows = [];

        foreach ($brands as $index => $slug) {
            $rows[$slug] = new MarketPrice(
                'gold',
                $slug,
                Gold::brands()[$slug] ?? $slug,
                // Round sample prices, stepping down per row so the
                // table reads as a table. Illustrative only.
                2400000.0 - ($index * 25000.0),
                null,
                'IDR',
                time()
            );
        }

        return $rows;
    }

    /**
     * The preview endpoint (kk_data_desk_preview): renders one
     * template's preview HTML for the current form values. Either
     * a saved template id (`template`) or the raw form fields
     * (`kind`, `symbol`, `brands` as a comma-separated list,
     * `palette`, `days`, `title`, `kicker`) — the form sends fields, the
     * widget forms send an id.
     *
     * @return array{html: string}
     */
    public static function ajaxPreview(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        $templateId = isset($raw['template']) ? sanitize_key((string) $raw['template']) : '';

        if ($templateId !== '') {
            $template = self::getTemplate($templateId);

            if ($template === null) {
                return ['html' => '<p>' . esc_html__('Template not found.', 'koperasikita') . '</p>'];
            }
        } else {
            $brands = [];
            if (isset($raw['brands']) && is_string($raw['brands']) && trim($raw['brands']) !== '') {
                $brands = array_values(array_filter(array_map('sanitize_key', explode(',', $raw['brands']))));
            }

            $template = [
                'id' => '',
                'name' => '',
                'kind' => self::sanitizeKind($raw['kind'] ?? self::KIND_PANEL),
                'symbol' => isset($raw['symbol']) ? sanitize_text_field((string) $raw['symbol']) : 'gold:antam',
                'brands' => $brands,
                'palette' => self::sanitizePalette(isset($raw['palette']) ? (string) $raw['palette'] : self::DEFAULT_PALETTE),
                'days' => isset($raw['days']) ? max(3, min(30, (int) $raw['days'])) : self::DEFAULT_DAYS,
                'title' => isset($raw['title']) ? sanitize_text_field((string) $raw['title']) : '',
                'kicker' => isset($raw['kicker']) ? sanitize_text_field((string) $raw['kicker']) : '',
            ];
            $template = self::sanitizeTemplate('', $template);
        }

        return ['html' => self::renderPreview($template)];
    }

    /**
     * The template fields, read from a request array and sanitised —
     * the one reader for the form POST (DataDeskTemplatesPage) and
     * the AJAX save below, so the two saves can never drift apart.
     * Brands arrive either as an array (native form POST, field
     * kk_template_brands[]) or as a comma-separated list (AJAX).
     *
     * @param array<string, mixed> $raw
     * @return array{name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string}
     */
    public static function templateDataFromRequest(array $raw): array
    {
        $brands = [];
        if (isset($raw['kk_template_brands']) && is_array($raw['kk_template_brands'])) {
            $brands = array_values(array_map('sanitize_key', $raw['kk_template_brands']));
        } elseif (isset($raw['kk_template_brands']) && is_string($raw['kk_template_brands']) && trim($raw['kk_template_brands']) !== '') {
            $brands = array_values(array_filter(array_map('sanitize_key', explode(',', $raw['kk_template_brands']))));
        }

        return [
            'name' => isset($raw['kk_template_name']) ? sanitize_text_field((string) $raw['kk_template_name']) : '',
            'kind' => isset($raw['kk_template_kind']) ? sanitize_key((string) $raw['kk_template_kind']) : self::KIND_PANEL,
            'symbol' => isset($raw['kk_template_symbol']) ? sanitize_text_field((string) $raw['kk_template_symbol']) : 'gold:antam',
            'brands' => $brands,
            'palette' => isset($raw['kk_template_palette']) ? sanitize_key((string) $raw['kk_template_palette']) : self::DEFAULT_PALETTE,
            'days' => isset($raw['kk_template_days']) ? (int) $raw['kk_template_days'] : self::DEFAULT_DAYS,
            'title' => isset($raw['kk_template_title']) ? sanitize_text_field((string) $raw['kk_template_title']) : '',
            'kicker' => isset($raw['kk_template_kicker']) ? sanitize_text_field((string) $raw['kk_template_kicker']) : '',
        ];
    }

    /**
     * The save endpoint (kk_data_desk_save_template): saves one
     * template from the edit form's fields and answers with the id
     * it was saved under, so the form can stay on the page (no
     * redirect) and a second save updates the same template. The
     * shared nonce + manage_options are enforced by Ajax::dispatch.
     *
     * @return array{id: string, message: string}
     */
    public static function ajaxSaveTemplate(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);
        $id = isset($raw['kk_template_id']) ? sanitize_key((string) $raw['kk_template_id']) : '';
        $id = self::saveTemplate($id, self::templateDataFromRequest($raw));

        return [
            'id' => $id,
            'message' => __('Template saved.', 'koperasikita'),
        ];
    }

    /**
     * All stored templates, in the order they were created. There
     * are no built-in defaults: with no stored templates the list
     * is empty.
     *
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string}>
     */
    public static function templates(): array
    {
        $templates = [];
        $stored = get_option(self::TEMPLATES_OPTION, []);

        if (!is_array($stored)) {
            return $templates;
        }

        foreach ($stored as $id => $row) {
            if (!is_string($id) || $id === '' || !is_array($row)) {
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
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string}>
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
     * The first stored template of one kind, or null when none
     * exists. Widgets fall back to this when the template they
     * point at is gone — never to a built-in.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string}|null
     */
    public static function firstTemplateOfKind(string $kind): ?array
    {
        $kind = self::sanitizeKind($kind);

        foreach (self::templates() as $template) {
            if ($template['kind'] === $kind) {
                return $template;
            }
        }

        return null;
    }

    /**
     * One template by id, or null when no stored template has that
     * id. Callers fall back to the first stored template of the
     * matching kind — never to a built-in.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string}|null
     */
    public static function getTemplate(string $id): ?array
    {
        $templates = self::templates();

        return $templates[$id] ?? null;
    }

    /**
     * Save a template. An empty id mints a fresh one; an existing
     * id updates that template in place. Returns the id the
     * template was saved under.
     *
     * @param array<string, mixed> $data
     */
    public static function saveTemplate(string $id, array $data): string
    {
        $stored = get_option(self::TEMPLATES_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        if ($id === '') {
            $id = 'tpl_' . wp_generate_uuid4();
        }

        $stored[$id] = self::sanitizeTemplate($id, $data);
        update_option(self::TEMPLATES_OPTION, $stored);

        return $id;
    }

    /**
     * Delete a template. Returns true when a template was removed.
     */
    public static function deleteTemplate(string $id): bool
    {
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
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string}
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
            'kicker' => isset($row['kicker']) ? sanitize_text_field((string) $row['kicker']) : '',
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
     * The translated label for a palette id, for the edit-form
     * select and the list page. PALETTES itself stays plain strings
     * (a const cannot call __()), so the translation lives here.
     */
    public static function paletteLabel(string $value): string
    {
        return match ($value) {
            'dark-green' => __('Dark Green', 'koperasikita'),
            'light' => __('Light', 'koperasikita'),
            'dark' => __('Dark', 'koperasikita'),
            'auto' => __('Follow site theme', 'koperasikita'),
            default => $value,
        };
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

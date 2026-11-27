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
 * A Data Desk panel shows one symbol - a gold brand (Antam, UBS, ...)
 * or a stock (IHSG, LQ45) - over the last N days: a line chart of the
 * daily price, the current price with its day change, the buyback
 * price with its spread (gold only), and the weekly performance with
 * its up-streak. Everything is computed from real stored data; a
 * symbol with no usable history gets a placeholder chart and honest
 * empty stats instead of invented numbers.
 *
 * Gold history comes from the Logam Mulia price table (one row per
 * brand per day, with sell + buyback). Stocks have no history table,
 * so their chart is always a placeholder and their buyback stat is
 * empty - the current price and day change still come from the
 * Markets provider (Yahoo).
 */
final class DataDesk
{
    /** Option holding the custom Data Desk templates (id => template). */
    public const TEMPLATES_OPTION = 'kk_data_desk_templates';

    /** The built-in default panel template id. It is code, not a stored row. */
    public const DEFAULT_TEMPLATE_ID = 'default';

    /** A template id belongs to a built-in when it is the code-defined default id. */
    public static function isBuiltIn(string $id): bool
    {
        return $id === self::DEFAULT_TEMPLATE_ID;
    }

    /**
     * A built-in is overridden when a stored row exists under its
     * id. The row holds the user's edited values; Restore deletes
     * it and the template renders its code-defined values again.
     */
    public static function isOverridden(string $id): bool
    {
        if (!self::isBuiltIn($id)) {
            return false;
        }

        $stored = get_option(self::TEMPLATES_OPTION, []);

        return is_array($stored) && isset($stored[$id]) && is_array($stored[$id]);
    }

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
     * The three stat blocks under a panel's chart, in display
     * order: sell price, buyback, weekly performance. Each block
     * has an editable label + sub-line (stored per template in
     * `stats`) and can be hidden (stored in `hidden_blocks`).
     */
    public const STAT_BLOCKS = ['sell', 'buyback', 'performance'];

    /**
     * The three column headers of a table template, in display
     * order: brand, price per gram, last-updated date. Each header
     * has an editable label (stored per template in `headers`,
     * empty = the default label), edited inline in the editor
     * preview like the panel stat labels.
     */
    public const HEADER_FIELDS = ['brand', 'price', 'updated'];

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
     * window in days - the two values the automatic title is built
     * from. Unknown placeholders are left untouched, so a typo never
     * eats text. NOTE: this {day} is NOT the footer's {day} (day of
     * the month) - the two token systems must not be "unified".
     */
    public static function replaceTitleTokens(string $title, string $label, int $days): string
    {
        return strtr($title, [
            '{label}' => $label,
            '{day}'   => (string) $days,
        ]);
    }

    // Placeholder settings for the panel's date line and stat notes.
    // A placeholder configures how a computed value is presented,
    // never the value itself. Each option has one default, so a
    // template without a setting renders exactly as before.
    public const DATE_FORMATS = [
        'full' => 'Full',
        'short' => 'Short',
        'numeric' => 'Numeric',
    ];
    public const DEFAULT_DATE_FORMAT = 'full';

    public const PERCENT_TYPES = [
        'daily' => 'Daily change',
        'period' => 'Whole period',
    ];
    public const DEFAULT_PERCENT_TYPE = 'daily';

    public const ZONE_COLORS = [
        'green' => 'Green',
        'red' => 'Red',
        'gold' => 'Gold',
    ];
    public const DEFAULT_ZONE_COLOR = 'green';

    public static function dateFormatLabel(string $value): string
    {
        return match ($value) {
            'short' => __('Short', 'koperasikita'),
            'numeric' => __('Numeric', 'koperasikita'),
            'full' => __('Full', 'koperasikita'),
            default => $value,
        };
    }

    public static function percentTypeLabel(string $value): string
    {
        return match ($value) {
            'period' => __('Whole period', 'koperasikita'),
            'daily' => __('Daily change', 'koperasikita'),
            default => $value,
        };
    }

    public static function zoneColorLabel(string $value): string
    {
        return match ($value) {
            'red' => __('Red', 'koperasikita'),
            'gold' => __('Gold', 'koperasikita'),
            'green' => __('Green', 'koperasikita'),
            default => $value,
        };
    }

    public static function sanitizeDateFormat(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        return isset(self::DATE_FORMATS[$value]) ? $value : self::DEFAULT_DATE_FORMAT;
    }

    public static function sanitizePercentType(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        return isset(self::PERCENT_TYPES[$value]) ? $value : self::DEFAULT_PERCENT_TYPE;
    }

    public static function sanitizeZoneColor(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        return isset(self::ZONE_COLORS[$value]) ? $value : self::DEFAULT_ZONE_COLOR;
    }

    /** @return array{date_format: string, percent: string, zone_color: string} */
    public static function sanitizePlaceholders(mixed $value): array
    {
        $row = is_array($value) ? $value : [];

        return [
            'date_format' => self::sanitizeDateFormat($row['date_format'] ?? self::DEFAULT_DATE_FORMAT),
            'percent' => self::sanitizePercentType($row['percent'] ?? self::DEFAULT_PERCENT_TYPE),
            'zone_color' => self::sanitizeZoneColor($row['zone_color'] ?? self::DEFAULT_ZONE_COLOR),
        ];
    }

    /** @return array{date_format: string, percent: string, zone_color: string} */
    public static function placeholdersFromFields(array $raw, string $prefix = ''): array
    {
        return [
            'date_format' => isset($raw[$prefix . 'placeholder_date_format']) ? self::sanitizeDateFormat($raw[$prefix . 'placeholder_date_format']) : self::DEFAULT_DATE_FORMAT,
            'percent' => isset($raw[$prefix . 'placeholder_percent']) ? self::sanitizePercentType($raw[$prefix . 'placeholder_percent']) : self::DEFAULT_PERCENT_TYPE,
            'zone_color' => isset($raw[$prefix . 'placeholder_zone_color']) ? self::sanitizeZoneColor($raw[$prefix . 'placeholder_zone_color']) : self::DEFAULT_ZONE_COLOR,
        ];
    }

    /**
     * One date of the chart range in the configured placeholder
     * format: full ("21 November 2026"), short ("21 Nov 2026") or
     * numeric ("21/11/2026"). An empty or unparseable date resolves
     * to nothing, never a guessed date.
     */
    public static function formatPlaceholderDate(string $date, string $format): string
    {
        if ($date === '') {
            return '';
        }

        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }

        return match (self::sanitizeDateFormat($format)) {
            'short' => date_i18n('j M Y', $ts),
            'numeric' => date_i18n('d/m/Y', $ts),
            default => date_i18n('j F Y', $ts),
        };
    }

    /**
     * The chart range ("21 November 2026 - 27 November 2026") in
     * the configured placeholder format. The full format is the
     * range the panel data already computed, returned untouched,
     * so the default rendering never changes.
     */
    public static function formatDateRange(array $data, string $format): string
    {
        $range = (string) ($data['date_range'] ?? '');
        if (self::sanitizeDateFormat($format) === 'full') {
            return $range;
        }

        $points = is_array($data['points'] ?? null) ? $data['points'] : [];
        if ($points === []) {
            return $range;
        }

        $first = (string) ($points[0]['date'] ?? '');
        $last = (string) ($points[count($points) - 1]['date'] ?? '');
        if ($first === '' || $last === '') {
            return $range;
        }

        $start = self::formatPlaceholderDate($first, $format);
        $end = self::formatPlaceholderDate($last, $format);

        return $first === $last ? $start : $start . ' - ' . $end;
    }

    /**
     * Replace the date-line / stat-note placeholders in a panel
     * format string with values from the panel's own data. {start}
     * / {end} / {date} are the chart range ends in the configured
     * date format, {year} the year of the last date, {day} / {days}
     * the chart window, {label} the symbol name, {arrow} / {delta}
     * / {delta_pct} the change (daily or whole period, per the
     * configured percent type), {spread} the sell-buyback spread,
     * {streak} the up-streak in days and {zone} the zone name in
     * the configured zone colour.
     * Unknown placeholders stay literal, so a typo never eats
     * text, the same rule as the title tokens. The literal text
     * is escaped here, so the result is safe to print as-is; the
     * only markup it can carry is the {zone} span, built from a
     * validated colour class and an escaped zone name.
     */
    public static function replacePlaceholders(string $text, array $data, int $days, array $placeholders = []): string
    {
        $placeholders = self::sanitizePlaceholders($placeholders);
        $label = (string) ($data['label'] ?? '');
        $points = is_array($data['points'] ?? null) ? $data['points'] : [];
        $first = $points !== [] ? $points[0] : null;
        $last = $points !== [] ? $points[count($points) - 1] : null;
        $price = isset($data['price']) && $data['price'] !== null ? (float) $data['price'] : null;
        $currency = (string) ($data['currency'] ?? 'IDR');

        $firstDate = is_array($first) && isset($first['date']) ? (string) $first['date'] : '';
        $lastDate = is_array($last) && isset($last['date']) ? (string) $last['date'] : '';
        $firstPrice = is_array($first) && isset($first['price']) ? (float) $first['price'] : null;
        $lastYear = '';
        if ($lastDate !== '') {
            $lastTs = strtotime($lastDate);
            $lastYear = $lastTs === false ? '' : date_i18n('Y', $lastTs);
        }

        if ($placeholders['percent'] === 'period') {
            $changePct = isset($data['week_change']) && $data['week_change'] !== null ? (float) $data['week_change'] : null;
            $changeAbs = $price !== null && $firstPrice !== null ? $price - $firstPrice : null;
        } else {
            $changePct = isset($data['change']) && $data['change'] !== null ? (float) $data['change'] : null;
            $changeAbs = isset($data['change_abs']) && $data['change_abs'] !== null ? (float) $data['change_abs'] : null;
        }

        $arrow = $changePct === null ? '' : ((float) $changePct >= 0 ? '↑' : '↓');
        $zoneUp = !isset($data['week_change']) || $data['week_change'] === null || (float) $data['week_change'] >= 0;
        $zoneName = $zoneUp ? __('zona hijau', 'koperasikita') : __('zona merah', 'koperasikita');
        $zoneClass = 'kk-data-desk-zone kk-data-desk-zone--' . $placeholders['zone_color'];

        return strtr(esc_html($text), [
            '{label}' => esc_html($label),
            '{day}' => esc_html((string) $days),
            '{days}' => esc_html((string) $days),
            '{start}' => esc_html(self::formatPlaceholderDate($firstDate, $placeholders['date_format'])),
            '{end}' => esc_html(self::formatPlaceholderDate($lastDate, $placeholders['date_format'])),
            '{date}' => esc_html(self::formatPlaceholderDate($lastDate, $placeholders['date_format'])),
            '{year}' => esc_html($lastYear),
            '{arrow}' => esc_html($arrow),
            '{delta}' => $changeAbs === null ? '' : esc_html(Markets::formatPrice(abs($changeAbs), $currency)),
            '{delta_pct}' => $changePct === null ? '' : esc_html(Markets::formatChange($changePct)),
            '{spread}' => !isset($data['spread']) || $data['spread'] === null ? '' : esc_html(number_format_i18n((float) $data['spread'], 1) . '%'),
            '{streak}' => esc_html((string) (int) ($data['streak'] ?? 0)),
            '{zone}' => sprintf('<span class="%s">%s</span>', esc_attr($zoneClass), esc_html($zoneName)),
        ]);
    }

    /**
     * The preview for one template (panel or table), rendered
     * through the real template files with clearly-labelled SAMPLE
     * data - never real prices presented as live. The templates
     * page list, the template form and the widget forms all use
     * this one path, so the preview is always the layout the
     * widget will actually render.
     *
     * $editable is true only for the template editor's own preview
     * (the templates page form + its AJAX refresh): there the stat
     * blocks are inline-editable and carry their eye toggles, and
     * a hidden block stays visible, dimmed, so it can be
     * un-hidden. Widget-form previews and the frontend never get
     * those - a hidden block simply does not render there.
     */
    public static function renderPreview(array $template, bool $editable = false): string
    {
        ob_start();

        if (($template['kind'] ?? self::KIND_PANEL) === self::KIND_TABLE) {
            get_template_part('template-parts/gold/data-desk', null, [
                'brands' => self::previewTablePrices($template),
                'client' => [],
                'palette' => $template['palette'] ?? self::DEFAULT_PALETTE,
                'preview' => true,
                'headers' => $template['headers'] ?? [],
                'editable' => $editable,
            ]);
        } else {
            get_template_part('template-parts/data-desk/panel', null, [
                'data' => self::previewData($template),
                'palette' => $template['palette'] ?? self::DEFAULT_PALETTE,
                'days' => (int) ($template['days'] ?? self::DEFAULT_DAYS),
                'title' => $template['title'] ?? '',
                'kicker' => $template['kicker'] ?? '',
                'date_line' => $template['date_line'] ?? '',
                'stats' => $template['stats'] ?? [],
                'hidden_blocks' => $template['hidden_blocks'] ?? [],
                'placeholders' => $template['placeholders'] ?? [],
                'preview' => true,
                'editable' => $editable,
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
     * so the preview shows the layout - the numbers are illustrative
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
                : date_i18n('j F Y', strtotime($first['date'])) . ' - ' . date_i18n('j F Y', strtotime($last['date'])),
        ];
    }

    /**
     * Sample table rows for a template: one MarketPrice per brand
     * the template shows (empty = all enabled brands), with fixed
     * round sample prices - never a live snapshot. The table
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
     * `palette`, `days`, `title`, `kicker`, `date_line`, the per-block stat
     * texts `stat_{block}_label` / `stat_{block}_sub`, the per-header
     * texts `header_{brand|price|updated}`, and
     * `hidden_blocks` as a comma-separated list) - the form sends
     * fields, the widget forms send an id. A fields preview is
     * the template editor's own, so it renders editable
     * (inline-editable stats + eye toggles); an id preview is a
     * widget-form preview, so it does not.
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
                'date_line' => isset($raw['date_line']) ? sanitize_text_field((string) $raw['date_line']) : '',
                'stats' => self::statsFromFields($raw),
                'headers' => self::headersFromFields($raw),
                'hidden_blocks' => self::hiddenBlocksFromFields($raw),
                'placeholders' => self::placeholdersFromFields($raw),
            ];
            $template = self::sanitizeTemplate('', $template);

            return ['html' => self::renderPreview($template, true)];
        }

        return ['html' => self::renderPreview($template)];
    }

    /**
     * The template fields, read from a request array and sanitised -
     * the one reader for the form POST (DataDeskTemplatesPage) and
     * the AJAX save below, so the two saves can never drift apart.
     * Brands arrive either as an array (native form POST, field
     * kk_template_brands[]) or as a comma-separated list (AJAX).
     *
     * @param array<string, mixed> $raw
     * @return array{name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}
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
            'date_line' => isset($raw['kk_template_date_line']) ? sanitize_text_field((string) $raw['kk_template_date_line']) : '',
            'stats' => self::statsFromFields($raw, 'kk_template_'),
            'headers' => self::headersFromFields($raw, 'kk_template_'),
            'hidden_blocks' => self::hiddenBlocksFromFields($raw, 'kk_template_'),
            'placeholders' => self::placeholdersFromFields($raw, 'kk_template_'),
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
     * The per-block stat texts out of a form submission: the six
     * flat `stat_{block}_label` / `stat_{block}_sub` fields the
     * template form (and its AJAX refresh) sends. Empty means
     * "use the computed text". Unknown blocks are dropped.
     * $prefix is the form's field prefix ('kk_template_' for the
     * save POST, '' for the AJAX refresh params).
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{label: string, sub: string}>
     */
    public static function statsFromFields(array $raw, string $prefix = ''): array
    {
        $stats = [];

        foreach (self::STAT_BLOCKS as $key) {
            $stats[$key] = [
                'label' => isset($raw[$prefix . 'stat_' . $key . '_label']) ? sanitize_text_field((string) $raw[$prefix . 'stat_' . $key . '_label']) : '',
                'sub' => isset($raw[$prefix . 'stat_' . $key . '_sub']) ? sanitize_text_field((string) $raw[$prefix . 'stat_' . $key . '_sub']) : '',
            ];
        }

        return $stats;
    }

    /**
     * The per-header table texts out of a form submission: the
     * three flat `header_{brand|price|updated}` fields the
     * template form (and its AJAX refresh) sends. Empty means
     * "use the default label". $prefix is the form's field prefix
     * ('kk_template_' for the save POST, '' for the AJAX refresh
     * params) - the same shape as statsFromFields().
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function headersFromFields(array $raw, string $prefix = ''): array
    {
        $headers = [];

        foreach (self::HEADER_FIELDS as $key) {
            $headers[$key] = isset($raw[$prefix . 'header_' . $key]) ? sanitize_text_field((string) $raw[$prefix . 'header_' . $key]) : '';
        }

        return $headers;
    }

    /**
     * The hidden stat blocks out of a form submission: the
     * `hidden_blocks` field as a comma-separated list of block
     * keys. Unknown keys are dropped. $prefix is the form's field
     * prefix ('kk_template_' for the save POST, '' for the AJAX
     * refresh params).
     *
     * @param array<string, mixed> $raw
     * @return array<int, string>
     */
    public static function hiddenBlocksFromFields(array $raw, string $prefix = ''): array
    {
        $field = $prefix . 'hidden_blocks';

        if (!isset($raw[$field]) || !is_string($raw[$field]) || trim($raw[$field]) === '') {
            return [];
        }

        return array_values(array_intersect(
            array_filter(array_map('sanitize_key', explode(',', $raw[$field]))),
            self::STAT_BLOCKS
        ));
    }

    /**
     * Clean the per-block stat text overrides into their canonical
     * shape: one label + sub-line per block, '' meaning "use the
     * computed text". Unknown blocks are dropped.
     *
     * @param mixed $value
     * @return array<string, array{label: string, sub: string}>
     */
    public static function sanitizeStats(mixed $value): array
    {
        $stats = [];

        foreach (self::STAT_BLOCKS as $key) {
            $row = is_array($value) && isset($value[$key]) && is_array($value[$key]) ? $value[$key] : [];
            $stats[$key] = [
                'label' => isset($row['label']) ? sanitize_text_field((string) $row['label']) : '',
                'sub' => isset($row['sub']) ? sanitize_text_field((string) $row['sub']) : '',
            ];
        }

        return $stats;
    }

    /**
     * Clean the per-header table texts into their canonical
     * shape: one label per column, '' meaning "use the default
     * label". Unknown keys are dropped.
     *
     * @param mixed $value
     * @return array<string, string>
     */
    public static function sanitizeHeaders(mixed $value): array
    {
        $headers = [];

        foreach (self::HEADER_FIELDS as $key) {
            $row = is_array($value) && isset($value[$key]) ? $value[$key] : '';
            $headers[$key] = sanitize_text_field((string) $row);
        }

        return $headers;
    }

    /**
     * The built-in default template (a panel): Antam over 7 days
     * in the dark-green palette, the same shape as the live
     * "ANTAM 7 Hari" user template. It is defined in code; editing
     * it stores an override under its id, and Restore deletes that
     * override - the built-in itself is never deleted.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}
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
            'kicker' => '',
            'date_line' => '',
            'stats' => self::sanitizeStats([]),
            'headers' => self::sanitizeHeaders([]),
            'hidden_blocks' => [],
            'placeholders' => self::sanitizePlaceholders([]),
        ];
    }

    /**
     * The name a template shows in a picker: the stored name, with
     * a Built-in marker on the code-defined template, so a
     * widget form can tell them apart from user templates.
     */
    public static function displayName(array $template): string
    {
        $name = (string) ($template['name'] ?? '');

        if ($name === '') {
            $name = __('Untitled template', 'koperasikita');
        }

        return self::isBuiltIn((string) ($template['id'] ?? ''))
            ? $name . ' (' . __('Built-in', 'koperasikita') . ')'
            : $name;
    }

    /**
     * All templates: the built-in first, then the stored user
     * templates in the order they were created. A stored row under
     * a built-in id is that template's override - it replaces the
     * code-defined values with the user's edited ones.
     *
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}>
     */
    public static function templates(): array
    {
        $templates = [
            self::DEFAULT_TEMPLATE_ID => self::defaultTemplate(),
        ];
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
     * @return array<string, array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}>
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
     * The first template of one kind - the built-in when no user
     * template of that kind exists. Widgets fall back to this when
     * the template they point at is gone.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}|null
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
     * One template by id, or null when no template (built-in or
     * stored) has that id. Callers fall back to the first template
     * of the matching kind.
     *
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}|null
     */
    public static function getTemplate(string $id): ?array
    {
        $templates = self::templates();

        return $templates[$id] ?? null;
    }

    /**
     * Save a template. An empty id mints a fresh one; an existing
     * id updates that template in place. Saving a built-in id
     * stores the values as that template's override - the
     * code-defined values come back when the override is restored.
     * Returns the id the template was saved under.
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
     * Delete a template. Built-ins can never be deleted - they are
     * restored instead. Returns true when a template was removed.
     */
    public static function deleteTemplate(string $id): bool
    {
        if (self::isBuiltIn($id)) {
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
     * Restore a built-in template: delete the stored override, so
     * the template renders its code-defined values again. Returns
     * true when an override was removed. User templates are never
     * restored - they are deleted instead.
     */
    public static function restoreTemplate(string $id): bool
    {
        if (!self::isBuiltIn($id)) {
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
     * @return array{id: string, name: string, kind: string, symbol: string, brands: array<int, string>, palette: string, days: int, title: string, kicker: string, date_line: string, stats: array<string, array{label: string, sub: string}>, headers: array<string, string>, hidden_blocks: array<int, string>, placeholders: array{date_format: string, percent: string, zone_color: string}}
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
            'date_line' => isset($row['date_line']) ? sanitize_text_field((string) $row['date_line']) : '',
            'stats' => self::sanitizeStats($row['stats'] ?? []),
            'headers' => self::sanitizeHeaders($row['headers'] ?? []),
            'hidden_blocks' => isset($row['hidden_blocks']) && is_array($row['hidden_blocks'])
                ? array_values(array_intersect(array_map('sanitize_key', $row['hidden_blocks']), self::STAT_BLOCKS))
                : [],
            'placeholders' => self::sanitizePlaceholders($row['placeholders'] ?? []),
        ];
    }

    /**
     * The symbols a Data Desk panel can show, as "market:symbol" =>
     * label. Gold brands first, then stocks. Crypto is left out on
     * purpose - the panel is a gold / index desk, not a coin ticker.
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
            : date_i18n('j F Y', strtotime($first['date'])) . ' - ' . date_i18n('j F Y', strtotime($last['date']));

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

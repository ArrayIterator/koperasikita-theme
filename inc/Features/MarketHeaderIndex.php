<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Providers\MarketPrice;
use KoperasiKita\Widgets\MarketsWidget;

/**
 * Market Index: the strip below the header (after the nav and
 * the ticker, above the hero) showing picked market indices -
 * symbol, price, day change - with a snapshot caption.
 *
 * Data comes only from what the sync already stored
 * (Markets::storedPrices(): fresh transient plus last good).
 * The strip never fetches from the upstream itself: fresh data
 * is the manual sync's job in wp-admin and the cron auto-sync's
 * job, on their own schedule. When the cron has not run yet the
 * strip serves the stored data, however old; an item with no
 * stored price is skipped, never invented. The strip renders
 * nothing at all - not even an empty bar - when disabled, when
 * Markets is off, or when no picked item has data.
 * The strip has no background of its own: the items sit directly
 * on the page background and follow the site colour scheme.
 *
 * One option holds the whole configuration, shared by the
 * Customizer section and the Koperasi Kita -> Market Index admin
 * page: ['enabled' => bool, 'items' => [{item, hidden}],
 * 'cycle_interval' => int, 'snapshot_label' => string,
 * 'snapshot_show_datetime' => bool, 'snapshot_format' => string,
 * 'snapshot_show_mobile' => bool].
 * `cycle_interval` is the seconds between items when the strip
 * cycles one item at a time on small screens - 7 by default,
 * deliberately not the news ticker's 5, so the two rotations
 * never step together.
 * `items` is the picked set in picked order (max 16, market|symbol
 * values from MarketsWidget::allItems()); `hidden` flags items the
 * admin page hid - hidden items stay stored so they can be shown
 * again, but never render. An empty picked list means the default
 * set, the same as the Customizer picker's empty state.
 * The snapshot caption under the strip is customizable:
 * `snapshot_label` is the text before the date and time (empty
 * hides the label), `snapshot_show_datetime` toggles the day and
 * time, and `snapshot_format` is the PHP date format for them
 * (default 'l H.i T', e.g. "Sabtu 08.30 WIB").
 * `snapshot_show_mobile` toggles the whole snapshot caption on
 * small screens only; off hides it below 768px so the strip gets
 * the full row, desktop is untouched.
 */
final class MarketHeaderIndex
{
    public const OPTION = 'kk_market_header_index';

    public const DEFAULT_CYCLE_INTERVAL = 7;
    public const MIN_CYCLE_INTERVAL = 2;
    public const MAX_CYCLE_INTERVAL = 120;

    /**
     * The strip's client-side refresh endpoint: GET
     * /kk-market-prices/ answers the strip's prices for every
     * market plus the pre-formatted snapshot caption, so the
     * browser can refresh the strip without a page reload even
     * when the page itself is served from a full-page cache
     * (LiteSpeed Cache / Cloudflare). The query var is the
     * plain-permalink form of the same endpoint.
     */
    public const STRIP_ENDPOINT_SLUG = 'kk-market-prices';
    public const STRIP_QUERY_VAR = 'kk_market_strip';

    /** LocalStorage lifetime of the strip's cached data, minutes. */
    public const STRIP_CACHE_TTL_MINUTES = 30;

    /** PHP date format for the snapshot day and time. */
    public const DEFAULT_SNAPSHOT_FORMAT = 'l H.i T';

    public const MAX_SNAPSHOT_LABEL_LENGTH = 80;
    public const MAX_SNAPSHOT_FORMAT_LENGTH = 64;

    /** @return array<int, array{item: string, hidden: bool}> */
    public static function defaultItems(): array
    {
        return [
            ['item' => 'stock|ihsg', 'hidden' => false],
            ['item' => 'forex|usdidr', 'hidden' => false],
            ['item' => 'gold|antam', 'hidden' => false],
            ['item' => 'commodity|brent', 'hidden' => false],
        ];
    }

    /** @return array{enabled: bool, items: array<int, array{item: string, hidden: bool}>, cycle_interval: int, snapshot_label: string, snapshot_show_datetime: bool, snapshot_format: string, snapshot_show_mobile: bool} */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [
                'enabled' => true,
                'items' => self::defaultItems(),
                'cycle_interval' => self::DEFAULT_CYCLE_INTERVAL,
                'snapshot_label' => __('Market Snapshot', 'koperasikita'),
                'snapshot_show_datetime' => true,
                'snapshot_format' => self::DEFAULT_SNAPSHOT_FORMAT,
                'snapshot_show_mobile' => true,
            ];
        }

        $items = self::sanitizeItems($stored['items'] ?? []);

        return [
            'enabled' => isset($stored['enabled']) ? (bool) $stored['enabled'] : true,
            'items' => $items !== [] ? $items : self::defaultItems(),
            'cycle_interval' => self::sanitizeCycleInterval($stored['cycle_interval'] ?? self::DEFAULT_CYCLE_INTERVAL),
            'snapshot_label' => isset($stored['snapshot_label']) ? self::sanitizeSnapshotLabel($stored['snapshot_label']) : __('Market Snapshot', 'koperasikita'),
            'snapshot_show_datetime' => isset($stored['snapshot_show_datetime']) ? self::sanitizeSnapshotShowDatetime($stored['snapshot_show_datetime']) : true,
            'snapshot_format' => isset($stored['snapshot_format']) ? self::sanitizeSnapshotFormat($stored['snapshot_format']) : self::DEFAULT_SNAPSHOT_FORMAT,
            'snapshot_show_mobile' => isset($stored['snapshot_show_mobile']) ? self::sanitizeSnapshotShowMobile($stored['snapshot_show_mobile']) : true,
        ];
    }

    public static function sanitizeEnabled(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function sanitizeCycleInterval(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_CYCLE_INTERVAL;
        }

        return max(self::MIN_CYCLE_INTERVAL, min(self::MAX_CYCLE_INTERVAL, (int) $value));
    }

    /**
     * The caption label: plain text, trimmed, at most 80 chars.
     * An empty string is kept - it hides the label.
     */
    public static function sanitizeSnapshotLabel(mixed $value): string
    {
        if (!is_string($value)) {
            return __('Market Snapshot', 'koperasikita');
        }

        $label = trim(sanitize_text_field(wp_unslash($value)));

        return mb_substr($label, 0, self::MAX_SNAPSHOT_LABEL_LENGTH);
    }

    public static function sanitizeSnapshotShowDatetime(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function sanitizeSnapshotShowMobile(mixed $value): bool
    {
        return (bool) $value;
    }

    /**
     * The caption day/time format: a PHP date format, trimmed, at
     * most 64 chars. An empty format falls back to the default, so
     * the date never renders as nothing by accident.
     */
    public static function sanitizeSnapshotFormat(mixed $value): string
    {
        if (!is_string($value)) {
            return self::DEFAULT_SNAPSHOT_FORMAT;
        }

        $format = trim(sanitize_text_field(wp_unslash($value)));

        if ($format === '') {
            return self::DEFAULT_SNAPSHOT_FORMAT;
        }

        return mb_substr($format, 0, self::MAX_SNAPSHOT_FORMAT_LENGTH);
    }

    /**
     * Accepts the stored shape ([{item, hidden}]), a plain list of
     * market|symbol strings, or the Customizer picker's JSON string.
     * Keeps known pairs in order, drops unknowns and duplicates,
     * caps at 16 (MarketsWidget::MAX_ITEMS).
     *
     * @return array<int, array{item: string, hidden: bool}>
     */
    public static function sanitizeItems(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $known = MarketsWidget::allItems();
        $items = [];

        foreach ($value as $row) {
            if (is_string($row)) {
                $row = ['item' => $row, 'hidden' => false];
            }

            if (!is_array($row) || !isset($row['item']) || !is_string($row['item'])) {
                continue;
            }

            $item = $row['item'];

            if (!isset($known[$item])) {
                continue;
            }

            foreach ($items as $kept) {
                if ($kept['item'] === $item) {
                    continue 2;
                }
            }

            $items[] = ['item' => $item, 'hidden' => !empty($row['hidden'])];

            if (count($items) >= MarketsWidget::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /** @return string[] Picked market|symbol values in picked order, hidden ones dropped. */
    public static function shownItems(): array
    {
        $shown = [];

        foreach (self::settings()['items'] as $row) {
            if (!$row['hidden']) {
                $shown[] = $row['item'];
            }
        }

        return $shown;
    }

    public static function isEnabled(): bool
    {
        return self::settings()['enabled'] && Markets::isEnabled();
    }

    /** @return MarketPrice[] Shown items with a real price, in picked order. */
    public static function renderItems(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $pricesByMarket = [];
        $flat = [];

        foreach (self::shownItems() as $item) {
            [$market, $symbol] = explode('|', $item, 2);

            if (!isset($pricesByMarket[$market])) {
                $pricesByMarket[$market] = Markets::storedPrices($market);
            }

            $price = $pricesByMarket[$market][$symbol] ?? null;

            if ($price instanceof MarketPrice && $price->hasData()) {
                $flat[] = $price;
            }
        }

        return $flat;
    }

    /**
     * The snapshot time is the latest updated timestamp across the
     * rendered items - the moment the strip's data was taken.
     *
     * @param MarketPrice[] $prices
     */
    public static function snapshotTime(array $prices): ?int
    {
        $latest = null;

        foreach ($prices as $price) {
            if ($price->updated > 0 && ($latest === null || $price->updated > $latest)) {
                $latest = $price->updated;
            }
        }

        return $latest;
    }

    /**
     * The snapshot caption text: the label, the day and time, or
     * both, joined with a middot - or '' when there is nothing to
     * show (empty label and day/time off, or day/time on with no
     * snapshot timestamp). The day and time use wp_date() with the
     * true Unix timestamp: date_i18n() with a true timestamp
     * renders 7 hours early under Asia/Jakarta (WP 7.1.5), which
     * is why the caption used to read 08.30 for data taken at
     * 15.30.
     */
    public static function captionText(?int $snapshot): string
    {
        $settings = self::settings();
        $parts = [];

        if ($settings['snapshot_label'] !== '') {
            $parts[] = $settings['snapshot_label'];
        }

        if ($settings['snapshot_show_datetime'] && $snapshot !== null && $snapshot > 0) {
            $parts[] = wp_date($settings['snapshot_format'], $snapshot);
        }

        return implode(' · ', $parts);
    }

    /**
     * Keys the caller does not pass keep their stored value, so a
     * form that only edits part of the option (the admin page
     * never sends `enabled`, the Customizer never calls this)
     * cannot reset the rest to defaults.
     *
     * @param array<string, mixed> $settings
     * @return array{enabled: bool, items: array<int, array{item: string, hidden: bool}>, cycle_interval: int, snapshot_label: string, snapshot_show_datetime: bool, snapshot_format: string, snapshot_show_mobile: bool}
     */
    public static function update(array $settings): array
    {
        $current = self::settings();

        $saved = [
            'enabled' => isset($settings['enabled']) ? self::sanitizeEnabled($settings['enabled']) : $current['enabled'],
            'items' => isset($settings['items']) ? self::sanitizeItems($settings['items']) : $current['items'],
            'cycle_interval' => isset($settings['cycle_interval']) ? self::sanitizeCycleInterval($settings['cycle_interval']) : $current['cycle_interval'],
            'snapshot_label' => isset($settings['snapshot_label']) ? self::sanitizeSnapshotLabel($settings['snapshot_label']) : $current['snapshot_label'],
            'snapshot_show_datetime' => isset($settings['snapshot_show_datetime']) ? self::sanitizeSnapshotShowDatetime($settings['snapshot_show_datetime']) : $current['snapshot_show_datetime'],
            'snapshot_format' => isset($settings['snapshot_format']) ? self::sanitizeSnapshotFormat($settings['snapshot_format']) : $current['snapshot_format'],
            'snapshot_show_mobile' => isset($settings['snapshot_show_mobile']) ? self::sanitizeSnapshotShowMobile($settings['snapshot_show_mobile']) : $current['snapshot_show_mobile'],
        ];

        update_option(self::OPTION, $saved);

        return $saved;
    }

    /** Renders the strip, or nothing at all when it has no data. */
    public static function render(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $items = self::renderItems();

        if ($items === []) {
            return;
        }

        $snapshot = self::snapshotTime($items);

        get_template_part('template-parts/header/market-index', null, [
            'items' => $items,
            'snapshot' => $snapshot,
            'caption' => self::captionText($snapshot),
            'cycle_interval' => self::settings()['cycle_interval'],
        ]);
    }

    /**
     * The strip refresh endpoint: a pretty URL the browser fetches
     * instead of admin-ajax.php, mirroring the PWA endpoints
     * (add_rewrite_rule + query var + template_redirect). The rule
     * is registered unconditionally - the handler below is what
     * gates on the Markets feature, so enabling the feature later
     * needs no rewrite flush. The flush on theme switch is the
     * same as the PWA endpoints'.
     */
    public static function register(): void
    {
        add_rewrite_rule(
            '^' . preg_quote(self::STRIP_ENDPOINT_SLUG, '/') . '/?$',
            'index.php?' . self::STRIP_QUERY_VAR . '=1',
            'top'
        );
        add_filter('query_vars', [self::class, 'registerQueryVar']);
        // The canonical redirect would 301 /kk-market-prices to
        // its trailing-slash version, so the fetch would answer a
        // redirect instead of JSON. Disable it for this endpoint
        // only; every other URL keeps its normal redirect.
        add_filter('redirect_canonical', [self::class, 'disableCanonicalRedirect'], 10, 2);
        add_action('template_redirect', [self::class, 'serveStripEndpoint']);
        add_action('after_switch_theme', 'flush_rewrite_rules');
    }

    /** @param string[] $vars */
    public static function registerQueryVar(array $vars): array
    {
        $vars[] = self::STRIP_QUERY_VAR;

        return $vars;
    }

    /**
     * Disable WordPress's canonical (trailing-slash) redirect for
     * the strip endpoint. Returning false keeps the request on the
     * exact URL the browser asked for, so the endpoint answers
     * with a plain 200 instead of a 301.
     *
     * @param string|false $redirectUrl The URL WordPress wants to redirect to, or false.
     * @param string $requestedUrl The URL the browser actually asked for.
     * @return string|false False for the strip endpoint, untouched otherwise.
     */
    public static function disableCanonicalRedirect($redirectUrl, string $requestedUrl)
    {
        if (get_query_var(self::STRIP_QUERY_VAR) === '1') {
            return false;
        }

        return $redirectUrl;
    }

    /** Public URL of the strip refresh endpoint. */
    public static function stripEndpointUrl(): string
    {
        if (get_option('permalink_structure') === '') {
            return add_query_arg(self::STRIP_QUERY_VAR, '1', home_url('/'));
        }

        return home_url(self::STRIP_ENDPOINT_SLUG . '/');
    }

    /**
     * The endpoint payload: every market's stored prices (the same
     * shape as the REST /markets route, via
     * Markets::allStoredPrices() - stored only, never a live
     * fetch), the pre-formatted snapshot caption, and the snapshot
     * timestamp it was formatted from. The caption is formatted
     * server-side with the caption settings, so the browser only
     * swaps text.
     *
     * @return array{markets: array<string, array<string, array<string, mixed>>>, caption: string, snapshot: int|null}
     */
    public static function stripPayload(): array
    {
        $items = self::renderItems();
        $snapshot = self::snapshotTime($items);

        return [
            'markets' => Markets::allStoredPrices(),
            'caption' => self::captionText($snapshot),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Serves the strip endpoint: JSON with no-cache headers, so
     * the endpoint itself is never page-cached (a cached response
     * here would defeat the whole refresh). A disabled Markets
     * feature answers 404, like the REST routes. Exits after
     * answering, so WordPress never renders a page behind it.
     */
    public static function serveStripEndpoint(): void
    {
        if (get_query_var(self::STRIP_QUERY_VAR) !== '1') {
            return;
        }

        if (!Markets::isEnabled()) {
            status_header(404);
            nocache_headers();
            header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
            echo wp_json_encode(['error' => __('Markets are disabled.', 'koperasikita')]);
            exit;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
        echo wp_json_encode(self::stripPayload());
        exit;
    }
}

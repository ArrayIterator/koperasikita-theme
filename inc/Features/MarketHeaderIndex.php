<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Providers\MarketPrice;
use KoperasiKita\Widgets\MarketsWidget;

/**
 * Market header index: the strip below the header (after the
 * nav and the ticker, above the hero) showing picked market
 * indices - symbol, price, day change - with a snapshot timestamp.
 *
 * Data comes only from the Markets providers (Markets::prices(),
 * 15-minute transient plus last good, the same as the Markets
 * widget); an item with no price is skipped, never invented. The
 * strip renders nothing at all - not even an empty bar - when
 * disabled, when Markets is off, or when no picked item has data.
 * The strip has no background of its own: the items sit directly
 * on the page background and follow the site colour scheme.
 *
 * One option holds the whole configuration, shared by the
 * Customizer section and the Koperasi Kita -> Market Header Index
 * admin page: ['enabled' => bool, 'items' => [{item, hidden}],
 * 'cycle_interval' => int]. `cycle_interval` is the seconds
 * between items when the strip cycles one item at a time on small
 * screens - 7 by default, deliberately not the news ticker's 5,
 * so the two rotations never step together.
 * `items` is the picked set in picked order (max 16, market|symbol
 * values from MarketsWidget::allItems()); `hidden` flags items the
 * admin page hid - hidden items stay stored so they can be shown
 * again, but never render. An empty picked list means the default
 * set, the same as the Customizer picker's empty state.
 */
final class MarketHeaderIndex
{
    public const OPTION = 'kk_market_header_index';

    public const DEFAULT_CYCLE_INTERVAL = 7;
    public const MIN_CYCLE_INTERVAL = 2;
    public const MAX_CYCLE_INTERVAL = 120;

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

    /** @return array{enabled: bool, items: array<int, array{item: string, hidden: bool}>, cycle_interval: int} */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [
                'enabled' => true,
                'items' => self::defaultItems(),
                'cycle_interval' => self::DEFAULT_CYCLE_INTERVAL,
            ];
        }

        $items = self::sanitizeItems($stored['items'] ?? []);

        return [
            'enabled' => isset($stored['enabled']) ? (bool) $stored['enabled'] : true,
            'items' => $items !== [] ? $items : self::defaultItems(),
            'cycle_interval' => self::sanitizeCycleInterval($stored['cycle_interval'] ?? self::DEFAULT_CYCLE_INTERVAL),
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
                $pricesByMarket[$market] = Markets::prices($market);
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
     * @param array<string, mixed> $settings
     * @return array{enabled: bool, items: array<int, array{item: string, hidden: bool}>, cycle_interval: int}
     */
    public static function update(array $settings): array
    {
        $saved = [
            'enabled' => self::sanitizeEnabled($settings['enabled'] ?? true),
            'items' => self::sanitizeItems($settings['items'] ?? []),
            'cycle_interval' => self::sanitizeCycleInterval($settings['cycle_interval'] ?? self::DEFAULT_CYCLE_INTERVAL),
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

        get_template_part('template-parts/header/market-index', null, [
            'items' => $items,
            'snapshot' => self::snapshotTime($items),
            'cycle_interval' => self::settings()['cycle_interval'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Markets;

/**
 * Markets option registration.
 *
 * Owns the option registration and the sanitizer for the
 * `kk_markets_settings` option. The data layer (Markets) only reads
 * the option; nothing about the admin screen lives there. The
 * Settings -> Markets admin screen was removed (the markets are not
 * used), so there is no menu page for this option any more — the
 * stored value stays as it is.
 */
final class MarketsSettings
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        register_setting(Markets::OPTION, Markets::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
            'default' => [],
        ]);
    }

    /**
     * Sanitizer for the whole settings option. Unknown markets, symbols and
     * providers are dropped; a snapshot row without a positive price is
     * dropped, so an empty field means "no data" rather than a zero price.
     * Picker choices are kept only for known symbols of the market.
     *
     * @param mixed $input Raw submitted value.
     * @return array{providers: array<string, string>, snapshots: array<string, array<string, array{price: float, change: float|null, updated: int}>>, enabled: array<string, array<string, bool>>}
     */
    public static function sanitizeSettings($input): array
    {
        $clean = [
            'providers' => [],
            'snapshots' => [],
            'enabled' => [],
        ];

        if (!is_array($input)) {
            $input = [];
        }

        $providerIds = array_keys(Markets::providers());
        $submittedProviders = isset($input['providers']) && is_array($input['providers']) ? $input['providers'] : [];
        foreach (Markets::markets() as $market) {
            $chosen = $submittedProviders[$market] ?? 'manual';
            $clean['providers'][$market] = in_array($chosen, $providerIds, true) ? $chosen : 'manual';
        }

        // Picker choices: only known symbols of the market, only when
        // the checkbox was actually sent (unchecked = not shown).
        $submittedEnabled = isset($input['enabled']) && is_array($input['enabled']) ? $input['enabled'] : [];
        foreach (Markets::markets() as $market) {
            $rows = $submittedEnabled[$market] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            foreach (Markets::symbols($market) as $symbol => $label) {
                if (!empty($rows[$symbol])) {
                    $clean['enabled'][$market][$symbol] = true;
                }
            }
        }

        $now = time();
        $submittedSnapshots = isset($input['snapshots']) && is_array($input['snapshots']) ? $input['snapshots'] : [];
        foreach (Markets::markets() as $market) {
            $rows = $submittedSnapshots[$market] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            foreach (Markets::symbols($market) as $symbol => $label) {
                $row = $rows[$symbol] ?? null;
                if (!is_array($row)) {
                    continue;
                }

                $priceRaw = trim((string) ($row['price'] ?? ''));
                if ($priceRaw === '' || !is_numeric($priceRaw)) {
                    continue;
                }

                $price = (float) $priceRaw;
                if ($price <= 0) {
                    continue;
                }

                $changeRaw = trim((string) ($row['change'] ?? ''));
                $change = $changeRaw !== '' && is_numeric($changeRaw) ? (float) $changeRaw : null;

                $clean['snapshots'][$market][$symbol] = [
                    'price' => $price,
                    'change' => $change,
                    'updated' => $now,
                ];
            }
        }

        return $clean;
    }
}

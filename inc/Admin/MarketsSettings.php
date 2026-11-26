<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Markets;

/**
 * The Settings -> Markets admin screen.
 *
 * Owns the option registration, the sanitizer and the page markup for
 * the `kk_markets_settings` option. The data layer (Markets) only reads
 * the option; nothing about the admin screen lives there.
 */
final class MarketsSettings
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerSettingsPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettingsPage(): void
    {
        add_options_page(
            esc_html__('Markets', 'koperasikita'),
            esc_html__('Markets', 'koperasikita'),
            'manage_options',
            'kk-markets',
            [self::class, 'renderSettingsPage']
        );
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

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $settings = Markets::settings();
        $providers = Markets::providers();
        $labels = Markets::labels();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Markets', 'koperasikita'); ?></h1>
            <p><?php esc_html_e('Choose which provider supplies each market and keep the manual snapshot prices up to date. A market with no saved price shows an honest "no data" message on the site — prices are never invented.', 'koperasikita'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(Markets::OPTION); ?>
                <?php foreach (Markets::markets() as $market) : ?>
                    <?php
                    $chosenProvider = $settings['providers'][$market] ?? 'manual';
                    $enabled = Markets::enabledSymbols($market);
                    ?>
                    <h2><?php echo esc_html($labels[$market]); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="kk-provider-<?php echo esc_attr($market); ?>"><?php esc_html_e('Provider', 'koperasikita'); ?></label>
                            </th>
                            <td>
                                <select
                                    id="kk-provider-<?php echo esc_attr($market); ?>"
                                    name="<?php echo esc_attr(Markets::OPTION); ?>[providers][<?php echo esc_attr($market); ?>]"
                                    data-kk-market-provider="<?php echo esc_attr($market); ?>"
                                >
                                    <?php foreach ($providers as $id => $provider) : ?>
                                        <?php if (!$provider->supports($market)) {
                                            continue;
                                        } ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($chosenProvider, $id); ?>>
                                            <?php echo esc_html($provider->label()); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button
                                    type="button"
                                    class="button"
                                    data-kk-provider-picker-open="<?php echo esc_attr($market); ?>"
                                ><?php esc_html_e('Choose what to show', 'koperasikita'); ?></button>
                                <p class="description" data-kk-provider-picker-summary="<?php echo esc_attr($market); ?>">
                                    <?php
                                    /* translators: %s: comma-separated list of shown symbols. */
                                    printf(esc_html__('Showing: %s', 'koperasikita'), esc_html(implode(', ', array_values($enabled))));
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <?php foreach (Markets::symbols($market) as $symbol => $label) : ?>
                            <?php
                            $row = $settings['snapshots'][$market][$symbol] ?? [];
                            $price = isset($row['price']) ? (string) $row['price'] : '';
                            $change = isset($row['change']) && $row['change'] !== null ? (string) $row['change'] : '';
                            $updated = isset($row['updated']) ? (int) $row['updated'] : 0;
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="kk-price-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="number"
                                        min="0"
                                        step="any"
                                        inputmode="decimal"
                                        id="kk-price-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>"
                                        name="<?php echo esc_attr(Markets::OPTION); ?>[snapshots][<?php echo esc_attr($market); ?>][<?php echo esc_attr($symbol); ?>][price]"
                                        value="<?php echo esc_attr($price); ?>"
                                        placeholder="0"
                                    >
                                    <span><?php echo esc_html(Markets::currency($market)); ?></span>
                                    <label for="kk-change-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>">
                                        <?php esc_html_e('Change (%)', 'koperasikita'); ?>
                                    </label>
                                    <input
                                        type="number"
                                        step="any"
                                        inputmode="decimal"
                                        id="kk-change-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>"
                                        name="<?php echo esc_attr(Markets::OPTION); ?>[snapshots][<?php echo esc_attr($market); ?>][<?php echo esc_attr($symbol); ?>][change]"
                                        value="<?php echo esc_attr($change); ?>"
                                        placeholder="0.00"
                                    >
                                    <?php if ($updated > 0) : ?>
                                        <p class="description">
                                            <?php
                                            /* translators: %s: date the snapshot was last saved. */
                                            printf(esc_html__('Last saved: %s', 'koperasikita'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $updated)));
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>

                    <?php self::renderProviderPicker($market, $providers, $settings); ?>
                <?php endforeach; ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Provider picker popup for one market: after the admin picks a
     * provider, this popup lists exactly what that provider can
     * supply, and the admin ticks which symbols the strip and the
     * widgets show. One group per provider; the script shows only
     * the group of the selected provider and disables the rest, so
     * only the current provider's choices are submitted. Without
     * JavaScript the popup stays hidden and the market shows every
     * symbol the provider supplies (see Markets::enabledSymbols()).
     */
    private static function renderProviderPicker(string $market, array $providers, array $settings): void
    {
        $labels = Markets::labels();
        $enabled = Markets::enabledSymbols($market);
        $storedEnabled = $settings['enabled'][$market] ?? [];
        ?>
        <div
            class="kk-provider-picker"
            data-kk-provider-picker="<?php echo esc_attr($market); ?>"
            hidden
            role="dialog"
            aria-modal="true"
            aria-labelledby="kk-provider-picker-title-<?php echo esc_attr($market); ?>"
        >
            <div class="kk-provider-picker-backdrop" data-kk-provider-picker-close></div>
            <div class="kk-provider-picker-dialog">
                <h2 id="kk-provider-picker-title-<?php echo esc_attr($market); ?>">
                    <?php
                    /* translators: %s: market label (Gold, Stocks, Crypto). */
                    printf(esc_html__('What to show: %s', 'koperasikita'), esc_html($labels[$market] ?? $market));
                    ?>
                </h2>
                <p class="description">
                    <?php esc_html_e('Tick the items this provider supplies that the market strip and the widgets should show. Unchecked items are hidden everywhere.', 'koperasikita'); ?>
                </p>
                <?php foreach ($providers as $id => $provider) : ?>
                    <?php if (!$provider->supports($market)) {
                        continue;
                    } ?>
                    <?php $supplies = $provider->supplies($market); ?>
                    <fieldset
                        class="kk-provider-picker-group"
                        data-kk-provider-picker-group="<?php echo esc_attr($id); ?>"
                        <?php echo $id === ($settings['providers'][$market] ?? 'manual') ? '' : 'hidden'; ?>
                    >
                        <legend><?php echo esc_html($provider->label()); ?></legend>
                        <?php if ($supplies === []) : ?>
                            <p class="description"><?php esc_html_e('This provider supplies nothing for this market.', 'koperasikita'); ?></p>
                        <?php endif; ?>
                        <?php foreach ($supplies as $symbol => $label) : ?>
                            <?php
                            // Checked when the symbol is enabled now, or
                            // when nothing was ever picked for this market
                            // (fresh install: everything is shown).
                            $checked = is_array($storedEnabled) && $storedEnabled !== []
                                ? !empty($storedEnabled[$symbol])
                                : isset($enabled[$symbol]);
                            ?>
                            <label class="kk-provider-picker-item">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(Markets::OPTION); ?>[enabled][<?php echo esc_attr($market); ?>][<?php echo esc_attr($symbol); ?>]"
                                    value="1"
                                    <?php checked($checked); ?>
                                    <?php echo $id === ($settings['providers'][$market] ?? 'manual') ? '' : 'disabled'; ?>
                                >
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
                <p class="kk-provider-picker-actions">
                    <button type="button" class="button button-primary" data-kk-provider-picker-close>
                        <?php esc_html_e('Done', 'koperasikita'); ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }
}

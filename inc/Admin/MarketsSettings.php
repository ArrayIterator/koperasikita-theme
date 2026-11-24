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
     *
     * @param mixed $input Raw submitted value.
     * @return array{providers: array<string, string>, snapshots: array<string, array<string, array{price: float, change: float|null, updated: int}>>}
     */
    public static function sanitizeSettings($input): array
    {
        $clean = [
            'providers' => [],
            'snapshots' => [],
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
                                >
                                    <?php foreach ($providers as $id => $provider) : ?>
                                        <?php if (!$provider->supports($market)) {
                                            continue;
                                        } ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($settings['providers'][$market], $id); ?>>
                                            <?php echo esc_html($provider->label()); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                <?php endforeach; ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

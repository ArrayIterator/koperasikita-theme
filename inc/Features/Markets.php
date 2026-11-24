<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * A single market price snapshot.
 *
 * Prices are plain data: a value in the given currency, the day change in
 * percent, and the Unix timestamp the snapshot was saved. A null price means
 * "no data" — renderers must treat that as an honest empty state, never as
 * zero and never as a made-up number.
 */
final class MarketPrice
{
    public function __construct(
        public readonly string $market,
        public readonly string $symbol,
        public readonly string $label,
        public readonly ?float $price,
        public readonly ?float $change,
        public readonly string $currency,
        public readonly int $updated
    ) {
    }

    public function hasData(): bool
    {
        return $this->price !== null;
    }
}

/**
 * Contract for anything that can supply prices for a market.
 *
 * Third-party providers plug in through the `koperasikita/markets/providers`
 * filter, which receives an array of MarketPriceProvider keyed by provider id.
 * The Manual snapshot provider below is the built-in default for every market.
 */
interface MarketPriceProvider
{
    public function id(): string;

    public function label(): string;

    public function supports(string $market): bool;

    /** @return array<string, MarketPrice> Prices keyed by symbol. */
    public function prices(string $market): array;
}

/**
 * Manual snapshot provider: prices typed in by an admin on the
 * Settings -> Markets screen and stored in the `kk_markets_settings` option.
 *
 * This is the v1 default for gold, stock and crypto. A market with no saved
 * snapshot returns no prices at all, so the frontend shows an honest
 * "no data" state instead of inventing numbers.
 */
final class ManualSnapshotProvider implements MarketPriceProvider
{
    public function id(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual snapshot';
    }

    public function supports(string $market): bool
    {
        return in_array($market, Markets::markets(), true);
    }

    public function prices(string $market): array
    {
        if (!$this->supports($market)) {
            return [];
        }

        $snapshots = Markets::settings()['snapshots'][$market] ?? [];
        $prices = [];

        foreach (Markets::symbols($market) as $symbol => $label) {
            $entry = is_array($snapshots[$symbol] ?? null) ? $snapshots[$symbol] : null;
            $price = $entry !== null && isset($entry['price']) ? (float) $entry['price'] : null;
            $change = $entry !== null && isset($entry['change']) ? (float) $entry['change'] : null;
            $updated = $entry !== null && isset($entry['updated']) ? (int) $entry['updated'] : 0;

            // A snapshot row only counts as data when it carries a price.
            // A saved change percent on its own is not a price.
            if ($price === null) {
                continue;
            }

            $prices[$symbol] = new MarketPrice(
                $market,
                $symbol,
                $label,
                $price,
                $change,
                Markets::currency($market),
                $updated
            );
        }

        return $prices;
    }
}

/**
 * Markets data layer: gold, stock and crypto prices.
 *
 * Provider choice (which provider supplies each market) and the manual
 * snapshots live in the `kk_markets_settings` option, edited on the
 * Settings -> Markets screen — never in the Customizer and never hardcoded
 * in a template. When the feature is disabled nothing here hooks in at all:
 * no settings page, no settings registration, no frontend output.
 */
final class Markets
{
    public const OPTION = 'kk_markets_settings';

    public const PROVIDERS_FILTER = 'koperasikita/markets/providers';

    /** @return array<int, string> Market slugs in display order. */
    public static function markets(): array
    {
        return ['gold', 'stock', 'crypto'];
    }

    /** @return array<string, string> Market slug => translatable label. */
    public static function labels(): array
    {
        return [
            'gold' => __('Gold', 'koperasikita'),
            'stock' => __('Stocks', 'koperasikita'),
            'crypto' => __('Crypto', 'koperasikita'),
        ];
    }

    /**
     * Known symbols per market (slug => label). Only these symbols can ever
     * carry a snapshot, which keeps the settings screen and the widgets in
     * agreement about what exists.
     *
     * @return array<string, string>
     */
    public static function symbols(string $market): array
    {
        if ($market === 'gold') {
            return array_merge(
                ['spot' => __('Gold spot', 'koperasikita')],
                Gold::brands()
            );
        }

        if ($market === 'stock') {
            return [
                'ihsg' => 'IHSG',
                'lq45' => 'LQ45',
            ];
        }

        if ($market === 'crypto') {
            return [
                'btc' => 'Bitcoin',
                'eth' => 'Ethereum',
                'sol' => 'Solana',
                'usdt' => 'Tether',
                'xrp' => 'XRP',
            ];
        }

        return [];
    }

    public static function currency(string $market): string
    {
        return 'IDR';
    }

    public static function isEnabled(): bool
    {
        return Features::isEnabled('markets');
    }

    /**
     * Full settings array, merged over the defaults so callers can always
     * read `providers` and `snapshots` without isset() guards.
     *
     * @return array{providers: array<string, string>, snapshots: array<string, array<string, array{price?: float, change?: float, updated?: int}>>}
     */
    public static function settings(): array
    {
        $defaults = [
            'providers' => [
                'gold' => 'manual',
                'stock' => 'manual',
                'crypto' => 'manual',
            ],
            'snapshots' => [],
        ];

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        $settings = array_merge($defaults, $stored);
        $settings['providers'] = is_array($settings['providers'])
            ? array_merge($defaults['providers'], $settings['providers'])
            : $defaults['providers'];
        $settings['snapshots'] = is_array($settings['snapshots']) ? $settings['snapshots'] : [];

        return $settings;
    }

    /** @return array<string, MarketPriceProvider> Providers keyed by id. */
    public static function providers(): array
    {
        $providers = apply_filters(self::PROVIDERS_FILTER, [
            'manual' => new ManualSnapshotProvider(),
        ]);

        if (!is_array($providers)) {
            return ['manual' => new ManualSnapshotProvider()];
        }

        $valid = [];
        foreach ($providers as $id => $provider) {
            if ($provider instanceof MarketPriceProvider && is_string($id) && $id !== '') {
                $valid[$id] = $provider;
            }
        }

        if (!isset($valid['manual'])) {
            $valid['manual'] = new ManualSnapshotProvider();
        }

        return $valid;
    }

    public static function providerFor(string $market): MarketPriceProvider
    {
        $providers = self::providers();
        $chosen = self::settings()['providers'][$market] ?? 'manual';

        if (isset($providers[$chosen]) && $providers[$chosen]->supports($market)) {
            return $providers[$chosen];
        }

        foreach ($providers as $provider) {
            if ($provider->supports($market)) {
                return $provider;
            }
        }

        return $providers['manual'];
    }

    /**
     * Prices for one market, keyed by symbol. Only symbols with a real
     * snapshot price are returned — an empty array means "no data", and the
     * caller renders an honest empty state.
     *
     * @return array<string, MarketPrice>
     */
    public static function prices(string $market): array
    {
        if (!self::isEnabled() || !in_array($market, self::markets(), true)) {
            return [];
        }

        return self::providerFor($market)->prices($market);
    }

    public static function formatPrice(float $price, string $currency = 'IDR'): string
    {
        if ($currency === 'IDR') {
            return 'Rp' . number_format($price, 0, ',', '.');
        }

        return number_format($price, 2, '.', ',') . ' ' . $currency;
    }

    public static function formatChange(?float $change): string
    {
        if ($change === null) {
            return '';
        }

        $sign = $change > 0 ? '+' : '';
        return $sign . number_format($change, 2, '.', ',') . '%';
    }

    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

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
        register_setting(self::OPTION, self::OPTION, [
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

        $providerIds = array_keys(self::providers());
        $submittedProviders = isset($input['providers']) && is_array($input['providers']) ? $input['providers'] : [];
        foreach (self::markets() as $market) {
            $chosen = $submittedProviders[$market] ?? 'manual';
            $clean['providers'][$market] = in_array($chosen, $providerIds, true) ? $chosen : 'manual';
        }

        $now = time();
        $submittedSnapshots = isset($input['snapshots']) && is_array($input['snapshots']) ? $input['snapshots'] : [];
        foreach (self::markets() as $market) {
            $rows = $submittedSnapshots[$market] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            foreach (self::symbols($market) as $symbol => $label) {
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

        $settings = self::settings();
        $providers = self::providers();
        $labels = self::labels();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Markets', 'koperasikita'); ?></h1>
            <p><?php esc_html_e('Choose which provider supplies each market and keep the manual snapshot prices up to date. A market with no saved price shows an honest "no data" message on the site — prices are never invented.', 'koperasikita'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <?php foreach (self::markets() as $market) : ?>
                    <h2><?php echo esc_html($labels[$market]); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="kk-provider-<?php echo esc_attr($market); ?>"><?php esc_html_e('Provider', 'koperasikita'); ?></label>
                            </th>
                            <td>
                                <select
                                    id="kk-provider-<?php echo esc_attr($market); ?>"
                                    name="<?php echo esc_attr(self::OPTION); ?>[providers][<?php echo esc_attr($market); ?>]"
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
                        <?php foreach (self::symbols($market) as $symbol => $label) : ?>
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
                                        name="<?php echo esc_attr(self::OPTION); ?>[snapshots][<?php echo esc_attr($market); ?>][<?php echo esc_attr($symbol); ?>][price]"
                                        value="<?php echo esc_attr($price); ?>"
                                        placeholder="0"
                                    >
                                    <span><?php echo esc_html(self::currency($market)); ?></span>
                                    <label for="kk-change-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>">
                                        <?php esc_html_e('Change (%)', 'koperasikita'); ?>
                                    </label>
                                    <input
                                        type="number"
                                        step="any"
                                        inputmode="decimal"
                                        id="kk-change-<?php echo esc_attr($market); ?>-<?php echo esc_attr($symbol); ?>"
                                        name="<?php echo esc_attr(self::OPTION); ?>[snapshots][<?php echo esc_attr($market); ?>][<?php echo esc_attr($symbol); ?>][change]"
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

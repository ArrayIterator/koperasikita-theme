<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Features\Gold;
use KoperasiKita\Features\Markets;
use KoperasiKita\Providers\GoldPricesTable;
use KoperasiKita\Providers\LogamMuliaApiProvider;

/**
 * Settings -> Gold Prices: the management page for the Logam Mulia
 * gold price sync, with five tabs.
 *
 * "Summary" (the default) is the overview: the price chart with
 * live brand + days filters, the sync facts (last sync, last run,
 * last update), and the enabled / disabled brand lists with their
 * current prices. "Gold Brands" holds the per-brand provider picks
 * and the per-brand Enabled switch in one form, one save.
 * "Sync" gathers everything sync-related: the sync status (last
 * sync, latest recorded date, which symbols are stored, the
 * failure backlog), a manual sync button that steps through the
 * enabled sources one at a time via AJAX with a real progress
 * bar, and the per-source status table (read-only - the sources'
 * enable/default switches live on Setting). "History" shows the
 * stored price history as both a list (table) and a dependency-free
 * inline SVG line chart (one line per brand), with a brand +
 * date-range filter and paging. "Setting" holds only the sync
 * settings: the sync source list (which Logam Mulia CSV sources
 * the sync imports, with a default source that runs first) and
 * the automatic sync schedule (how often, days per run, timeout).
 * Uses WP admin styles only - no heavy custom CSS.
 */
final class GoldPricesPage
{
    public const PAGE_SLUG = 'kk-gold-prices';

    /** Rows per page in the history list. */
    private const HISTORY_PER_PAGE = 20;

    /** Rows the Incremental Export fetches per chunk (100 or 500
     * per the user's call - 500 keeps a full-history export to a
     * handful of small queries without loading the server). */
    private const EXPORT_CHUNK = 500;

    private const NONCE_EXPORT = 'kk_gold_prices_export';

    /** Rows the history chart reads at most (filter applies, page does not). */
    private const HISTORY_CHART_CAP = 200;

    /** Chart geometry (viewBox units, scales to the container). Wide
     * aspect so the full-width render stays short. */
    private const CHART_WIDTH = 1200;
    private const CHART_HEIGHT = 260;
    private const CHART_PAD_LEFT = 84;
    private const CHART_PAD_RIGHT = 16;
    private const CHART_PAD_TOP = 16;
    private const CHART_PAD_BOTTOM = 32;

    /**
     * Line colours per brand (theme green/gold first, then
     * distinct hues so the lines stay apart).
     *
     * @var array<string, string>
     */
    private const CHART_COLORS = [
        'antam' => '#0B6B3A',
        'ubs' => '#C9962E',
        'galeri-24' => '#2fa36b',
        'lotus-archi' => '#7c3aed',
        'king-halim' => '#dc2626',
        'hartadinata' => '#2563eb',
    ];

    /**
     * Register the Summary chart endpoint and the contextual help
     * tabs. The Summary tab re-renders its chart through the
     * endpoint, so the chart follows the brand picker + days
     * selector without a page reload. The help tabs (the "Help"
     * tab at the top right, like the Pages screen) hook onto this
     * page's load hook, so get_current_screen() is the Gold
     * Prices screen when they are added.
     */
    public static function register(): void
    {
        Ajax::add('kk_gold_chart', [self::class, 'ajaxChart'], false, 'manage_options');

        add_action('load-' . AdminMenu::submenuHookSuffix(self::PAGE_SLUG), [self::class, 'addHelpTabs']);
        add_action('load-' . AdminMenu::submenuHookSuffix(self::PAGE_SLUG), [self::class, 'handleActionsOnLoad']);
    }

    /**
     * Run the form saves on the page's load hook - before any
     * output - so the redirect after a save can send its headers.
     * Calling the save handlers from render() (after the admin
     * chrome is printed) made every save land on a blank page:
     * the redirect could not send headers, then exit() killed
     * the page mid-render. The save itself always worked; only
     * the response was blank.
     */
    public static function handleActionsOnLoad(): void
    {
        self::handleGoldBrandsSave();
        self::handleSyncSettingsSave();
    }

    /**
     * The Incremental Export (admin-post.php): the stored price
     * history as a CSV download, by date range or all of it.
     * Both modes stream: GoldPricesTable::streamExportRows()
     * fetches one chunk at a time and each row is echoed to
     * php://output as it is read, so the export never holds the
     * whole history in memory. Runs on admin-post.php, before any
     * output, so the download headers can still be sent.
     */
    public static function handleExport(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer(self::NONCE_EXPORT);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce was just checked.
        $all = isset($_GET['all']) && (string) wp_unslash($_GET['all']) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce was just checked.
        $from = $all ? '' : self::historyDate(isset($_GET['from']) ? (string) wp_unslash($_GET['from']) : '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce was just checked.
        $to = $all ? '' : self::historyDate(isset($_GET['to']) ? (string) wp_unslash($_GET['to']) : '');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $suffix = $all || ($from === '' && $to === '')
            ? 'all-' . gmdate('Y-m-d')
            : ($from !== '' ? $from : 'start') . '-to-' . ($to !== '' ? $to : gmdate('Y-m-d'));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gold-prices-' . $suffix . '.csv"');

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, ['Provider', 'Brand', 'Sell price', 'Buyback price', 'Currency', 'Recorded date', 'Fetched at']);

        foreach (GoldPricesTable::streamExportRows($from, $to, self::EXPORT_CHUNK) as $row) {
            fputcsv($out, [
                $row['provider'],
                $row['symbol'],
                $row['sell_price'],
                $row['buyback_price'] ?? '',
                $row['currency'],
                $row['recorded_date'],
                gmdate('Y-m-d H:i:s', $row['fetched_at']),
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Contextual help tabs for this page: what Scraping is, what
     * each source provides, the schedule, and what each tab is
     * for. Short and plain - the tabs answer, they do not lecture.
     */
    public static function addHelpTabs(): void
    {
        $screen = get_current_screen();

        if (!$screen instanceof \WP_Screen) {
            return;
        }

        $screen->add_help_tab([
            'id' => 'kk-gold-scraping',
            'title' => __('Scraping', 'koperasikita'),
            'content' => '<p>' . esc_html__('Scraping fetches the gold prices from the web and stores one row per brand per day. Two parts do it: a GitHub Actions scraper that collects the prices, and the Logam Mulia API sync on this site that imports them into the price history. Only rows that are not in the database yet are inserted.', 'koperasikita') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'kk-gold-sources',
            'title' => __('Sources', 'koperasikita'),
            'content' => '<p>' . esc_html__('Galeri 24, UBS, Antam and Lotus Archi 1-gram prices come from Galeri 24. The Pegadaian 0.01-gram price, scaled to 1 gram, comes from Pegadaian. The Antam 1-gram fallback price comes from Harga Emas.net. Enable or disable sources on the Setting tab; the default source is scraped first.', 'koperasikita') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'kk-gold-schedule',
            'title' => __('Schedule', 'koperasikita'),
            'content' => '<p>' . esc_html__('The sync runs automatically on a schedule (WP-Cron). Turn it on or off, and pick how often, on the Setting tab — the next automatic run is shown there. You can also scrape on demand from the Sync tab.', 'koperasikita') . '</p>',
        ]);

        $screen->add_help_tab([
            'id' => 'kk-gold-tabs',
            'title' => __('Tabs', 'koperasikita'),
            'content' => '<p>' . esc_html__('Summary is the overview: the price chart with live filters, the sync facts, and the enabled and disabled brands. Gold Brands is where you pick each brand’s provider and switch brands on or off. Sync shows the scraping status and the manual scrape button. History is the stored price list with filters. Setting holds the sync settings only.', 'koperasikita') . '</p>',
        ]);
    }

    /**
     * The chart endpoint (kk_gold_chart): the chart HTML for the
     * picked brands over the picked days. `brands` is a
     * comma-separated list of brand slugs - empty means the
     * enabled brands (see summaryChartBrands). `days` is clamped
     * to 1-90 (default 7). Reuses renderChart + the History query,
     * so the Summary chart can never disagree with History.
     *
     * @return array{html: string}
     */
    public static function ajaxChart(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        $brands = [];
        if (isset($raw['brands']) && is_string($raw['brands']) && trim($raw['brands']) !== '') {
            $brands = array_values(array_filter(array_map('sanitize_key', explode(',', $raw['brands']))));
        }

        $days = isset($raw['days']) ? (int) $raw['days'] : 7;
        $days = max(1, min(90, $days));

        $from = date_i18n('Y-m-d', current_time('timestamp') - ($days - 1) * DAY_IN_SECONDS);
        $rows = GoldPricesTable::historyFiltered('', ['symbols' => self::summaryChartBrands($brands), 'from' => $from], self::HISTORY_CHART_CAP, 0, Gold::officialProviders());

        ob_start();
        self::renderChart($rows, Gold::brands(), true);
        $html = (string) ob_get_clean();

        return ['html' => $html];
    }

    /**
     * The brands a Summary chart query covers: the picked slugs
     * (unknown ones dropped), or the enabled brands when nothing
     * is picked, or every brand when none is enabled - an empty
     * filter never means an empty chart, same as History.
     *
     * @param array<int, string> $picked
     * @return array<int, string>
     */
    private static function summaryChartBrands(array $picked): array
    {
        $known = array_keys(Gold::brands());
        $brands = array_values(array_intersect($picked, $known));

        if ($brands === []) {
            $brands = Gold::enabledBrands();
        }

        return $brands === [] ? $known : $brands;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        // Saves run on the load hook (handleActionsOnLoad), never
        // here - see register().

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is display-only.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'summary';
        // Old slugs keep working as aliases, so bookmarks and
        // saved links land on the right new tab.
        $aliases = ['prices' => 'summary', 'status' => 'summary', 'settings' => 'setting', 'brands' => 'gold-brands'];
        $tab = $aliases[$tab] ?? $tab;
        $tab = in_array($tab, ['summary', 'gold-brands', 'sync', 'history', 'setting'], true) ? $tab : 'summary';
        $brands = Gold::brands();
        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap kk-gold-page" data-kk-gold-prices-page>
            <h1 class="kk-gold-title"><?php esc_html_e('Gold Prices', 'koperasikita'); ?></h1>
            <h2 class="kk-gold-tabs">
                <a href="<?php echo esc_url(add_query_arg('tab', 'summary', $pageUrl)); ?>" class="kk-gold-tab<?php echo $tab === 'summary' ? ' is-active' : ''; ?>"><?php esc_html_e('Summary', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'gold-brands', $pageUrl)); ?>" class="kk-gold-tab<?php echo $tab === 'gold-brands' ? ' is-active' : ''; ?>"><?php esc_html_e('Gold Brands', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'sync', $pageUrl)); ?>" class="kk-gold-tab<?php echo $tab === 'sync' ? ' is-active' : ''; ?>"><?php esc_html_e('Sync', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'history', $pageUrl)); ?>" class="kk-gold-tab<?php echo $tab === 'history' ? ' is-active' : ''; ?>"><?php esc_html_e('History', 'koperasikita'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'setting', $pageUrl)); ?>" class="kk-gold-tab<?php echo $tab === 'setting' ? ' is-active' : ''; ?>"><?php esc_html_e('Setting', 'koperasikita'); ?></a>
            </h2>
            <?php if ($tab === 'gold-brands') : ?>
                <?php self::renderGoldBrandsTab($brands); ?>
            <?php elseif ($tab === 'sync') : ?>
                <?php self::renderSyncTab(); ?>
            <?php elseif ($tab === 'history') : ?>
                <?php self::renderHistoryTab($brands); ?>
            <?php elseif ($tab === 'setting') : ?>
                <?php self::renderSettingsTab(); ?>
            <?php else : ?>
                <?php self::renderSummaryTab($brands); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save the Gold Brands form (Gold Brands tab): the per-brand
     * provider/fallback picks AND the enabled-brands switch, in
     * one form, one save. Nonce + capability checked, then redirect
     * back with a saved flag so a refresh never resubmits the form.
     * Validation lives in Gold::setBrandProviders() - a provider
     * that cannot supply its brand is dropped to Manual there -
     * and in Gold::setEnabledBrands(), which drops unknown slugs.
     * The enabled checkboxes submit only when ticked, so an absent
     * list means "none enabled" on purpose, not "unchanged".
     */
    private static function handleGoldBrandsSave(): void
    {
        if (!isset($_POST['kk_gold_brands_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_gold_brands', 'kk_gold_brands_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $rawProviders = isset($_POST['kk_brand_provider']) && is_array($_POST['kk_brand_provider'])
            ? wp_unslash($_POST['kk_brand_provider'])
            : [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $rawFallbacks = isset($_POST['kk_brand_fallback']) && is_array($_POST['kk_brand_fallback'])
            ? wp_unslash($_POST['kk_brand_fallback'])
            : [];

        $providers = [];
        foreach ($rawProviders as $symbol => $provider) {
            $providers[sanitize_key((string) $symbol)] = sanitize_key((string) $provider);
        }

        $fallbacks = [];
        foreach ($rawFallbacks as $symbol => $provider) {
            $fallbacks[sanitize_key((string) $symbol)] = sanitize_key((string) $provider);
        }

        Gold::setBrandProviders($providers, $fallbacks);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = isset($_POST['kk_enabled_brands']) ? wp_unslash($_POST['kk_enabled_brands']) : [];
        $slugs = is_array($raw)
            ? array_values(array_map('sanitize_key', $raw))
            : [];

        Gold::setEnabledBrands($slugs);

        wp_safe_redirect(add_query_arg(
            ['tab' => 'gold-brands', 'gold_brands_saved' => '1'],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Save the sync settings form (Setting tab): nonce +
     * capability checked, then redirect back with a saved flag so
     * a refresh never resubmits the form. Saving reschedules the
     * WP-Cron event to match (see LogamMuliaApiProvider). The
     * source checkboxes submit only when ticked, so an absent
     * list means "none enabled" on purpose, not "unchanged".
     */
    private static function handleSyncSettingsSave(): void
    {
        if (!isset($_POST['kk_gold_sync_settings_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        check_admin_referer('kk_gold_sync_settings', 'kk_gold_sync_settings_nonce');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $rawSources = isset($raw['kk_sync_sources']) && is_array($raw['kk_sync_sources'])
            ? $raw['kk_sync_sources']
            : [];
        $syncSources = array_values(array_map(
            'sanitize_key',
            array_map('strval', $rawSources)
        ));

        LogamMuliaApiProvider::saveSyncSettings([
            'enabled' => isset($raw['kk_sync_enabled']),
            'interval_hours' => isset($raw['kk_sync_interval']) ? (int) $raw['kk_sync_interval'] : 24,
            'batch_days' => isset($raw['kk_sync_batch']) ? (int) $raw['kk_sync_batch'] : 7,
            'timeout' => isset($raw['kk_sync_timeout']) ? (int) $raw['kk_sync_timeout'] : 8,
            'sync_sources' => $syncSources,
            'default_source' => isset($raw['kk_sync_default']) ? (string) $raw['kk_sync_default'] : '',
        ]);

        wp_safe_redirect(add_query_arg(
            ['tab' => 'setting', 'sync_settings_saved' => '1'],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Summary tab (the default): the price chart with live
     * filters, the sync facts, and the enabled / disabled brand
     * lists. The chart re-renders via AJAX (kk_gold_chart) when
     * the brand picker or the days selector changes - no page
     * reload. The old Status price table lives here as the two
     * brand lists; there is no Status tab anymore.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderSummaryTab(array $brands): void
    {
        $status = LogamMuliaApiProvider::status();
        $enabled = Gold::enabledBrands();
        $days = 7;
        $chart = GoldPricesTable::historyFiltered('', ['symbols' => self::summaryChartBrands($enabled), 'from' => date_i18n('Y-m-d', current_time('timestamp') - ($days - 1) * DAY_IN_SECONDS)], self::HISTORY_CHART_CAP, 0, Gold::officialProviders());
        $lastSync = $status['synced_at'] !== null
            ? date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $status['synced_at'])
            : __('Never', 'koperasikita');
        ?>
        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Price chart', 'koperasikita'); ?></h2>
        <p class="kk-gold-filters">
            <label for="kk-summary-days"><?php esc_html_e('Days', 'koperasikita'); ?></label>
            <select id="kk-summary-days" data-kk-gold-summary-days>
                <?php foreach ([7, 14, 30, 60, 90] as $option) : ?>
                    <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($days, $option); ?>><?php echo esc_html((string) $option); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <fieldset class="kk-gold-brand-picker" data-kk-gold-summary-brands>
            <legend class="screen-reader-text"><?php esc_html_e('Brands to show', 'koperasikita'); ?></legend>
            <?php foreach ($brands as $slug => $label) : ?>
                <label data-kk-gold-summary-brand-row>
                    <input type="checkbox" data-kk-gold-summary-brand value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled, true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p data-kk-gold-summary-status role="status" aria-live="polite"></p>
        <div data-kk-gold-summary-chart>
            <?php self::renderChart($chart, $brands, true); ?>
        </div>
        </section>

        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Sync status', 'koperasikita'); ?></h2>
        <table class="form-table kk-gold-facts" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Last sync', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($lastSync); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last run', 'koperasikita'); ?></th>
                    <td>
                        <?php
                        printf(
                            /* translators: %1$d: rows inserted, %2$d: rows updated (price changed or buyback filled), %3$d: rows skipped (already in database). */
                            esc_html__('Inserted %1$d, updated %2$d, skipped %3$d (already in database).', 'koperasikita'),
                            (int) $status['inserted'],
                            (int) ($status['updated'] ?? 0),
                            (int) $status['skipped']
                        );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last update', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($status['latest_date'] ?? __('None yet', 'koperasikita')); ?></td>
                </tr>
            </tbody>
        </table>
        </section>

        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Enabled brands', 'koperasikita'); ?></h2>
        <?php self::renderSummaryBrandList($brands, $enabled, true); ?>
        </section>

        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Disabled brands', 'koperasikita'); ?></h2>
        <?php self::renderSummaryBrandList($brands, $enabled, false); ?>
        </section>
        <?php
    }

    /**
     * One brand list for the Summary tab: the enabled brands, or
     * the disabled ones. Each row shows the brand name, its current
     * price, and the provider supplying it (the configured provider
     * when there is no price yet). An empty list says so honestly
     * instead of rendering an empty table.
     *
     * @param array<string, string> $brands Slug => label.
     * @param array<int, string> $enabled Enabled brand slugs.
     */
    private static function renderSummaryBrandList(array $brands, array $enabled, bool $showEnabled): void
    {
        $slugs = array_values(array_filter(
            array_keys($brands),
            static fn(string $slug): bool => in_array($slug, $enabled, true) === $showEnabled
        ));

        if ($slugs === []) {
            echo '<p>' . esc_html__($showEnabled ? 'All brands are disabled.' : 'No disabled brands.', 'koperasikita') . '</p>';

            return;
        }

        $prices = Markets::prices('gold');
        $configured = Gold::brandProviders();
        ?>
        <div class="kk-gold-table-wrap">
        <table class="widefat striped kk-gold-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Brand', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Current price', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Provider', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slugs as $slug) : ?>
                    <?php
                    $price = $prices[$slug] ?? null;
                    $provider = ($price !== null && $price->hasData() && $price->provider !== '')
                        ? Gold::providerLabel($price->provider)
                        : Gold::providerLabel($configured[$slug] ?? 'manual');
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($brands[$slug]); ?></strong></td>
                        <td>
                            <?php if ($price !== null && $price->hasData()) : ?>
                                <?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?>
                            <?php else : ?>
                                <?php esc_html_e('No data', 'koperasikita'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($provider); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /**
     * Gold Brands tab: the per-brand provider table (brand ->
     * provider + fallback) with the per-brand Enabled switch in
     * the same table - one form, one save. The Enabled switch is
     * the old "Enabled Gold Brands" form consolidated here; the
     * Setting tab no longer carries it.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderGoldBrandsTab(array $brands): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved flag is display-only.
        $saved = isset($_GET['gold_brands_saved']) && $_GET['gold_brands_saved'] === '1';
        ?>
        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Gold Brands', 'koperasikita'); ?></h2>
        <?php if ($saved) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Gold brands saved.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <p class="kk-gold-intro"><?php esc_html_e('Choose which provider supplies the price for each brand, and which brands the site shows. Only providers that can actually supply that brand are listed. When the primary provider has no price for a brand, its fallback provider is tried automatically. A disabled brand is hidden from the Gold widget and from the widget\'s Brands to show picker.', 'koperasikita'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=gold-brands')); ?>">
            <?php wp_nonce_field('kk_gold_brands', 'kk_gold_brands_nonce'); ?>
            <?php self::renderBrandProvidersTable(); ?>
            <?php submit_button(__('Save gold brands', 'koperasikita'), 'secondary'); ?>
        </form>
        </section>
        <?php
    }

    /**
     * Sync tab: everything about Scraping in one place - what it
     * does, the sync status (last sync, latest date, symbols
     * stored, the failure backlog), a manual scrape button that
     * steps through the enabled sources one at a time via AJAX with
     * a real progress bar, and the per-source status table.
     */
    private static function renderSyncTab(): void
    {
        $status = LogamMuliaApiProvider::status();
        $enabled = LogamMuliaApiProvider::enabledSyncSources();
        ?>
        <p class="kk-gold-intro"><?php esc_html_e('Scraping fetches the daily gold prices and stores one row per brand per day — only rows that are not in the database yet. It runs automatically via WP-Cron on the schedule on the Setting tab. Use the button below to scrape on demand.', 'koperasikita'); ?></p>

        <section class="kk-gold-card">
        <div class="kk-gold-card-head">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Sync status', 'koperasikita'); ?></h2>
        <button type="button" class="button button-primary" data-kk-gold-sync <?php disabled(empty($enabled)); ?>><?php esc_html_e('Sync now', 'koperasikita'); ?></button>
        </div>
        <?php self::renderStatus($status); ?>
        <?php if ($enabled === []) : ?>
            <p><?php esc_html_e('No scraping source is enabled. Enable one on the Setting tab.', 'koperasikita'); ?></p>
        <?php endif; ?>
        </section>

        <?php self::renderManualSync(); ?>

        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Scraping sources', 'koperasikita'); ?></h2>
        <?php self::renderSourceTable($status); ?>
        </section>
        <?php
    }

    /**
     * The manual scrape popup, on the Sync tab only. The Sync
     * now button lives in the Sync status card head; this renders
     * only the popup it opens. The scrape runs ONLY the enabled
     * sources, default first - see
     * LogamMuliaApiProvider::enabledSyncSources(). The popup uses
     * the same centered + blurred-backdrop pattern as the Data Desk
     * preview popup: progress bar + one pre holding every status
     * line, set by admin.ts (initGoldSync) as each source
     * finishes, and the run summary once in a success notice
     * below the pre. A failed source is reported on its own line
     * and does not stop the rest. The popup stays open with the
     * full result until closed; closing it reloads the page, so the
     * status, history list and chart show the new rows.
     */
    private static function renderManualSync(): void
    {
        ?>
        <div class="kk-data-desk-popup" id="kk-gold-sync-popup" hidden role="dialog" aria-modal="true" aria-labelledby="kk-gold-sync-popup-title">
            <div class="kk-data-desk-popup-backdrop" data-kk-gold-sync-popup-close></div>
            <div class="kk-data-desk-popup-panel" role="document">
                <div class="kk-data-desk-popup-head">
                    <h2 id="kk-gold-sync-popup-title"><?php esc_html_e('Manual scraping', 'koperasikita'); ?></h2>
                    <div class="kk-data-desk-popup-actions">
                        <button type="button" class="button" data-kk-gold-sync-popup-close><?php esc_html_e('Close', 'koperasikita'); ?></button>
                    </div>
                </div>
                <div class="kk-data-desk-popup-body">
                    <p><progress data-kk-gold-sync-bar max="100" value="0" style="width: 100%; max-width: 30rem;"></progress></p>
                    <pre data-kk-gold-sync-status role="status" aria-live="polite"></pre>
                    <div class="notice notice-success" data-kk-gold-sync-result role="status" aria-live="polite" hidden></div>
                    <p class="description"><?php esc_html_e('Closing this popup reloads the page so the status, history list and chart show the new prices.', 'koperasikita'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Setting tab: ONLY the sync settings - the scraping source
     * list (which sources Scraping imports, and which one runs
     * first) and the automatic sync schedule - one form, one save.
     * Manual and automatic scraping both follow the same enabled
     * list. Manual scraping itself, and the sync status, live on
     * the Sync tab; the per-brand Enabled switch lives on the
     * Gold Brands tab.
     */
    private static function renderSettingsTab(): void
    {
        $sync = LogamMuliaApiProvider::syncSettings();
        $status = LogamMuliaApiProvider::status();
        $nextRun = wp_next_scheduled(LogamMuliaApiProvider::CRON_HOOK);
        $labels = LogamMuliaApiProvider::sourceLabels();
        $descriptions = LogamMuliaApiProvider::sourceDescriptions();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved flag is display-only.
        $syncSettingsSaved = isset($_GET['sync_settings_saved']) && $_GET['sync_settings_saved'] === '1';
        ?>
        <section class="kk-gold-card">
        <p class="kk-gold-intro"><?php esc_html_e('Scraping fetches the gold prices from the sources below. Only enabled sources are scraped, manually and automatically. The default source is scraped first.', 'koperasikita'); ?></p>

        <h2 class="kk-gold-card-title"><?php esc_html_e('Scraping sources', 'koperasikita'); ?></h2>
        <?php if ($syncSettingsSaved) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Automatic sync settings saved.', 'koperasikita'); ?></p></div>
        <?php endif; ?>
        <p data-kk-gold-sync-running hidden><em><?php esc_html_e('A manual sync is running. Automatic sync settings are paused until it finishes.', 'koperasikita'); ?></em></p>
        <form method="post" data-kk-gold-sync-settings action="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=setting')); ?>">
            <?php wp_nonce_field('kk_gold_sync_settings', 'kk_gold_sync_settings_nonce'); ?>
            <div class="kk-gold-table-wrap">
            <table class="widefat striped kk-gold-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Source', 'koperasikita'); ?></th>
                        <th><?php esc_html_e('Enabled', 'koperasikita'); ?></th>
                        <th><?php esc_html_e('Default', 'koperasikita'); ?></th>
                        <th><?php esc_html_e('Last sync', 'koperasikita'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (LogamMuliaApiProvider::SOURCES as $source) : ?>
                        <?php
                        $sourceStatus = $status['sources'][$source] ?? [];
                        $lastSync = isset($sourceStatus['synced_at']) && $sourceStatus['synced_at'] !== null
                            ? date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $sourceStatus['synced_at'])
                            : __('Never', 'koperasikita');
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($descriptions[$source] ?? $source); ?></strong><br><span class="description"><?php echo esc_html($labels[$source] ?? $source); ?></span></td>
                            <td>
                                <input type="checkbox" name="kk_sync_sources[]" value="<?php echo esc_attr($source); ?>" <?php checked(in_array($source, $sync['sync_sources'], true)); ?> aria-label="<?php echo esc_attr(sprintf(__('Sync %s', 'koperasikita'), $labels[$source] ?? $source)); ?>">
                            </td>
                            <td>
                                <input type="radio" name="kk_sync_default" value="<?php echo esc_attr($source); ?>" <?php checked($sync['default_source'], $source); ?> aria-label="<?php echo esc_attr(sprintf(__('Default source: %s', 'koperasikita'), $labels[$source] ?? $source)); ?>">
                            </td>
                            <td><?php echo esc_html($lastSync); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <h3 class="kk-gold-card-subtitle"><?php esc_html_e('Automatic sync', 'koperasikita'); ?></h3>
            <table class="form-table kk-gold-facts" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable automatic sync', 'koperasikita'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="kk_sync_enabled" value="1" <?php checked($sync['enabled']); ?>>
                                <?php esc_html_e('Sync automatically on schedule (WP-Cron).', 'koperasikita'); ?>
                            </label>
                            <p class="description">
                                <?php
                                if (!$sync['enabled']) {
                                    esc_html_e('Automatic sync is off. Only manual sync runs.', 'koperasikita');
                                } elseif ($nextRun !== false) {
                                    printf(
                                        /* translators: %s: next scheduled run date/time. */
                                        esc_html__('Next automatic sync: %s.', 'koperasikita'),
                                        esc_html(date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $nextRun))
                                    );
                                } else {
                                    esc_html_e('No automatic sync is scheduled yet — it will be scheduled on the next page load.', 'koperasikita');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-interval"><?php esc_html_e('How often', 'koperasikita'); ?></label></th>
                        <td>
                            <select id="kk-sync-interval" name="kk_sync_interval">
                                <?php foreach (LogamMuliaApiProvider::intervalChoices() as $hours => $label) : ?>
                                    <option value="<?php echo esc_attr((string) $hours); ?>" <?php selected($sync['interval_hours'], $hours); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-batch"><?php esc_html_e('Days per run', 'koperasikita'); ?></label></th>
                        <td>
                            <input type="number" id="kk-sync-batch" name="kk_sync_batch" value="<?php echo esc_attr((string) $sync['batch_days']); ?>" min="1" max="30" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('How many recent days of price history each run imports (1–30). A fresh site gets a week of chart history on its first sync.', 'koperasikita'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kk-sync-timeout"><?php esc_html_e('Fetch timeout (seconds)', 'koperasikita'); ?></label></th>
                        <td>
                            <input type="number" id="kk-sync-timeout" name="kk_sync_timeout" value="<?php echo esc_attr((string) $sync['timeout']); ?>" min="5" max="60" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('How long to wait for each price file before giving up (5–60 seconds). A failed fetch is recorded in the backlog, never retried in a tight loop.', 'koperasikita'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Save sync settings', 'koperasikita'), 'secondary'); ?>
        </form>
        </section>
        <?php
    }

    /**
     * Per-brand provider table: one row per gold symbol (spot first,
     * then the brands in registry order). Each row shows the current
     * price + when it was updated, which provider actually supplied
     * it, and two selects: the provider and the fallback provider.
     * A select only lists providers that can actually supply that
     * brand. When the primary has no price, the fallback is tried
     * automatically. A brand with a description shows it as one
     * muted line under the brand name. Brand rows also carry the
     * Enabled switch (the spot row has none - spot is not a brand);
     * the table submits as one form with the Gold Brands tab.
     */
    private static function renderBrandProvidersTable(): void
    {
        $symbols = Gold::goldSymbols();
        $primaries = Gold::brandProviders();
        $fallbacks = Gold::brandFallbackProviders();
        $prices = Markets::prices('gold');
        $enabled = Gold::enabledBrands();
        $brands = Gold::brands();
        ?>
        <div class="kk-gold-brand-providers-wrap">
        <table class="widefat striped kk-gold-table kk-gold-brand-providers">
            <thead>
                <tr>
                    <th><?php esc_html_e('Brand', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Current price', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Updated', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Provider', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Fallback', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Supplied by', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Enabled', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($symbols as $symbol => $label) : ?>
                    <?php
                    $price = $prices[$symbol] ?? null;
                    $primary = $primaries[$symbol] ?? 'manual';
                    $fallback = $fallbacks[$symbol] ?? '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($label); ?></strong>
                            <?php $description = Gold::brandDescription($symbol); ?>
                            <?php if ($description !== '') : ?>
                                <br><span class="description"><?php echo esc_html($description); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($price !== null && $price->hasData()) : ?>
                                <?php echo esc_html(Markets::formatPrice((float) $price->price, $price->currency)); ?>
                            <?php else : ?>
                                <?php esc_html_e('No data', 'koperasikita'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($price !== null && $price->hasData() && $price->updated > 0) : ?>
                                <?php echo esc_html(date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $price->updated)); ?>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="kk_brand_provider[<?php echo esc_attr($symbol); ?>]" aria-label="<?php echo esc_attr(sprintf(__('Provider for %s', 'koperasikita'), $label)); ?>">
                                <?php foreach (Gold::providerChoicesForBrand($symbol) as $id => $choiceLabel) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($primary, $id); ?>>
                                        <?php echo esc_html($choiceLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="kk_brand_fallback[<?php echo esc_attr($symbol); ?>]" aria-label="<?php echo esc_attr(sprintf(__('Fallback provider for %s', 'koperasikita'), $label)); ?>">
                                <?php foreach (Gold::fallbackChoicesForBrand($symbol, $primary) as $id => $choiceLabel) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($fallback, $id); ?>>
                                        <?php echo esc_html($choiceLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?php if ($price !== null && $price->hasData() && $price->provider !== '') : ?>
                                <?php echo esc_html(Gold::providerLabel($price->provider)); ?>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (isset($brands[$symbol])) : ?>
                                <input type="checkbox" name="kk_enabled_brands[]" value="<?php echo esc_attr($symbol); ?>" <?php checked(in_array($symbol, $enabled, true)); ?> aria-label="<?php echo esc_attr(sprintf(__('Enabled: %s', 'koperasikita'), $label)); ?>">
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /**
     * History tab: the stored price history as a chart and a
     * list, with a brand + date-range filter and paging. The
     * filter is GET-based, so the URL stays linkable and the
     * filter survives refresh and paging. The chart follows the
     * filter (same filtered set, capped) but never the page
     * number; only the table paginates.
     *
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderHistoryTab(array $brands): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter and paging are display-only.
        $brand = isset($_GET['brand']) ? sanitize_key((string) wp_unslash($_GET['brand'])) : '';
        if ($brand !== '' && !isset($brands[$brand])) {
            $brand = '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter is display-only.
        $from = self::historyDate(isset($_GET['from']) ? (string) wp_unslash($_GET['from']) : '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter is display-only.
        $to = self::historyDate(isset($_GET['to']) ? (string) wp_unslash($_GET['to']) : '');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paging is display-only.
        $page = isset($_GET['paged']) ? max(1, absint((string) wp_unslash($_GET['paged']))) : 1;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter is display-only.
        $provider = isset($_GET['provider']) ? sanitize_key((string) wp_unslash($_GET['provider'])) : '';
        $knownProviders = GoldPricesTable::providers();
        if ($provider !== '' && !in_array($provider, $knownProviders, true)) {
            $provider = '';
        }

        $filters = ['symbol' => $brand, 'from' => $from, 'to' => $to];
        $filtered = $brand !== '' || $from !== '' || $to !== '' || $provider !== '';

        $chart = GoldPricesTable::historyFiltered($provider, $filters, self::HISTORY_CHART_CAP, 0, Gold::officialProviders());

        $total = GoldPricesTable::historyCount($provider, $filters, Gold::officialProviders());
        $pages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min($page, $pages);
        $rows = GoldPricesTable::historyFiltered(
            $provider,
            $filters,
            self::HISTORY_PER_PAGE,
            ($page - 1) * self::HISTORY_PER_PAGE,
            Gold::officialProviders()
        );

        $pageUrl = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $filterUrl = add_query_arg('tab', 'history', $pageUrl);
        if ($provider !== '') {
            $filterUrl = add_query_arg('provider', $provider, $filterUrl);
        }
        if ($brand !== '') {
            $filterUrl = add_query_arg('brand', $brand, $filterUrl);
        }
        if ($from !== '') {
            $filterUrl = add_query_arg('from', $from, $filterUrl);
        }
        if ($to !== '') {
            $filterUrl = add_query_arg('to', $to, $filterUrl);
        }
        $enabled = Gold::enabledBrands();
        ?>
        <section class="kk-gold-card">
        <h2 class="kk-gold-card-title"><?php esc_html_e('Price history', 'koperasikita'); ?></h2>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <input type="hidden" name="tab" value="history">
            <p class="kk-gold-filters">
                <label for="kk-history-provider"><?php esc_html_e('Provider', 'koperasikita'); ?></label>
                <select id="kk-history-provider" name="provider">
                    <option value=""><?php esc_html_e('All providers', 'koperasikita'); ?></option>
                    <?php foreach ($knownProviders as $known) : ?>
                        <option value="<?php echo esc_attr($known); ?>" <?php selected($provider, $known); ?>><?php echo esc_html($known); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="kk-history-brand"><?php esc_html_e('Brand', 'koperasikita'); ?></label>
                <select id="kk-history-brand" name="brand">
                    <option value=""><?php esc_html_e('All brands', 'koperasikita'); ?></option>
                    <?php foreach ($enabled as $slug) : ?>
                        <?php if (!isset($brands[$slug])) : continue; endif; ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($brand, $slug); ?>><?php echo esc_html($brands[$slug]); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="kk-history-from"><?php esc_html_e('From', 'koperasikita'); ?></label>
                <input type="date" id="kk-history-from" name="from" value="<?php echo esc_attr($from); ?>">
                <label for="kk-history-to"><?php esc_html_e('To', 'koperasikita'); ?></label>
                <input type="date" id="kk-history-to" name="to" value="<?php echo esc_attr($to); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'koperasikita'); ?>">
                <?php if ($filtered) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('tab', 'history', $pageUrl)); ?>"><?php esc_html_e('Clear filters', 'koperasikita'); ?></a>
                <?php endif; ?>
            </p>
        </form>
        <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="kk_export_gold_prices">
            <?php wp_nonce_field(self::NONCE_EXPORT); ?>
            <p class="kk-gold-filters">
                <strong><?php esc_html_e('Incremental Export', 'koperasikita'); ?></strong>
                <span><?php esc_html_e('Download the price history as CSV. Exports stream row by row from the database, so exporting the full history uses almost no memory.', 'koperasikita'); ?></span>
                <label for="kk-export-from"><?php esc_html_e('From', 'koperasikita'); ?></label>
                <input type="date" id="kk-export-from" name="from" value="<?php echo esc_attr($from); ?>">
                <label for="kk-export-to"><?php esc_html_e('To', 'koperasikita'); ?></label>
                <input type="date" id="kk-export-to" name="to" value="<?php echo esc_attr($to); ?>">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Export CSV', 'koperasikita'); ?>">
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('all', '1', admin_url('admin-post.php?action=kk_export_gold_prices')), self::NONCE_EXPORT)); ?>"><?php esc_html_e('Export All', 'koperasikita'); ?></a>
            </p>
        </form>
        <?php self::renderChart($chart, $brands, $filtered); ?>
        <?php self::renderHistoryTable($rows, $brands, $filtered); ?>
        <?php if ($pages > 1) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        'base' => add_query_arg('paged', '%#%', $filterUrl),
                        'format' => '',
                        'total' => $pages,
                        'current' => $page,
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
        </section>
        <?php
    }

    /**
     * A real Y-m-d filter date, or '' - anything else (garbage, a
     * wrong format, an impossible date) means "no filter".
     */
    private static function historyDate(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /**
     * Status block: last sync, latest recorded date, symbols
     * present, inserted vs skipped, and last error. The
     * per-source table lives beside it on the Sync tab (see
     * renderSourceTable).
     *
     * @param array{synced_at: int|null, latest_date: string|null, symbols: array<int, string>, inserted: int, skipped: int, error: string|null} $status
     */
    private static function renderStatus(array $status): void
    {
        $lastSync = $status['synced_at'] !== null
            ? date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $status['synced_at'])
            : __('Never', 'koperasikita');
        $symbols = $status['symbols'] !== [] ? implode(', ', $status['symbols']) : __('None yet', 'koperasikita');
        ?>
        <table class="form-table kk-gold-facts" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Last sync', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($lastSync); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Latest price date', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($status['latest_date'] ?? __('None yet', 'koperasikita')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Symbols stored', 'koperasikita'); ?></th>
                    <td><?php echo esc_html($symbols); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last run', 'koperasikita'); ?></th>
                    <td>
                        <?php
                        printf(
                            /* translators: %1$d: rows inserted, %2$d: rows updated (price changed or buyback filled), %3$d: rows skipped (already in DB). */
                            esc_html__('Inserted %1$d, updated %2$d, skipped %3$d (already in database).', 'koperasikita'),
                            (int) $status['inserted'],
                            (int) ($status['updated'] ?? 0),
                            (int) $status['skipped']
                        );
                        ?>
                    </td>
                </tr>
                <?php if ($status['error'] !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last error', 'koperasikita'); ?></th>
                        <td><?php echo esc_html($status['error']); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Per-source table: latest date, inserted/updated/skipped,
     * and the backlog note (a failed source stays in the backlog
     * and is retried later; after 3 failures it drops to lowest
     * priority - weekly retry only). Each row leads with what the
     * source imports; the site name sits below it, muted - the
     * table is about the scraping, not about the sites.
     *
     * @param array{sources: array<string, array{synced_at: int|null, inserted: int, updated: int, skipped: int, latest_date: string|null, error: string|null, failures: int, next_retry: int|null, deprioritised: bool}>} $status
     */
    private static function renderSourceTable(array $status): void
    {
        $labels = LogamMuliaApiProvider::sourceLabels();
        $descriptions = LogamMuliaApiProvider::sourceDescriptions();
        $rows = '';
        foreach (LogamMuliaApiProvider::SOURCES as $source) {
            $s = $status['sources'][$source] ?? [];
            $failures = (int) ($s['failures'] ?? 0);
            $deprioritised = !empty($s['deprioritised']);
            $nextRetry = isset($s['next_retry']) ? (int) $s['next_retry'] : null;

            if ($deprioritised && $nextRetry !== null) {
                /* translators: %1$d: failure count, %2$s: next retry date. */
                $note = sprintf(
                    __('Lowest priority — failed %1$d×, next retry %2$s.', 'koperasikita'),
                    $failures,
                    date_i18n(get_option('date_format', 'Y-m-d'), $nextRetry)
                );
            } elseif ($failures > 0 && $nextRetry !== null) {
                /* translators: %1$d: failure count, %2$s: next retry date. */
                $note = sprintf(
                    __('In backlog — failed %1$d×, next retry %2$s.', 'koperasikita'),
                    $failures,
                    date_i18n(get_option('date_format', 'Y-m-d'), $nextRetry)
                );
            } elseif (!empty($s['error'])) {
                $note = (string) $s['error'];
            } else {
                $note = '-';
            }

            $rows .= '<tr>'
                . '<td><strong>' . esc_html($descriptions[$source] ?? $source) . '</strong><br><span class="description">' . esc_html($labels[$source] ?? $source) . '</span></td>'
                . '<td>' . esc_html($s['latest_date'] ?? '-') . '</td>'
                . '<td>' . esc_html((string) ($s['inserted'] ?? 0)) . '</td>'
                . '<td>' . esc_html((string) ($s['updated'] ?? 0)) . '</td>'
                . '<td>' . esc_html((string) ($s['skipped'] ?? 0)) . '</td>'
                . '<td>' . esc_html($note) . '</td>'
                . '</tr>';
        }
        ?>
        <div class="kk-gold-table-wrap">
        <table class="widefat striped kk-gold-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Source', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Latest date', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Inserted', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Updated', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Skipped', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Status / backlog', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped above. ?></tbody>
        </table>
        </div>
        <?php
    }

    /**
     * Dependency-free inline SVG line chart: one line per brand,
     * sell price (per gram, IDR) over recorded date. Empty history
     * renders a short note instead of an empty chart - a different
     * note when a filter is active, so the page never suggests
     * syncing for rows the filter simply excluded. The figure spans
     * the full tab width (wide aspect, so the full-width render
     * stays short).
     *
     * @param array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}> $history
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderChart(array $history, array $brands, bool $filtered = false): void
    {
        // Group by symbol, oldest first, keeping only rows with a
        // date and a positive price.
        $series = [];
        foreach ($history as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            $date = (string) ($row['recorded_date'] ?? '');
            $price = (float) ($row['sell_price'] ?? 0);

            if ($symbol === '' || $date === '' || $price <= 0) {
                continue;
            }

            $series[$symbol][] = ['date' => $date, 'price' => $price];
        }

        foreach ($series as $symbol => $points) {
            usort($points, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
            $series[$symbol] = $points;
        }

        if ($series === []) {
            echo '<p>' . esc_html__($filtered ? 'No prices match the selected filters.' : 'No prices stored yet. Run a sync to fill the chart.', 'koperasikita') . '</p>';

            return;
        }

        $allDates = [];
        $min = null;
        $max = null;
        foreach ($series as $points) {
            foreach ($points as $point) {
                $allDates[] = $point['date'];
                $min = $min === null ? $point['price'] : min($min, $point['price']);
                $max = $max === null ? $point['price'] : max($max, $point['price']);
            }
        }

        $allDates = array_values(array_unique($allDates));
        sort($allDates);
        $firstDate = $allDates[0];
        $lastDate = $allDates[count($allDates) - 1];

        // Pad the price range so the lines never sit on the edge.
        $span = (float) ($max - $min);
        $pad = $span > 0 ? $span * 0.1 : (float) $max * 0.05;
        $min -= $pad;
        $max += $pad;

        $plotW = self::CHART_WIDTH - self::CHART_PAD_LEFT - self::CHART_PAD_RIGHT;
        $plotH = self::CHART_HEIGHT - self::CHART_PAD_TOP - self::CHART_PAD_BOTTOM;
        $start = strtotime($firstDate . ' 00:00:00');
        $end = strtotime($lastDate . ' 00:00:00');
        $timeSpan = max(1, $end - $start);

        $x = static fn(string $date): float => self::CHART_PAD_LEFT + ((strtotime($date . ' 00:00:00') - $start) / $timeSpan) * $plotW;
        $y = static fn(float $price): float => self::CHART_PAD_TOP + (1 - ($price - $min) / max(1.0, $max - $min)) * $plotH;

        $lines = '';
        $legend = '';
        foreach ($series as $symbol => $points) {
            $color = self::CHART_COLORS[$symbol] ?? '#374151';
            $coords = [];
            foreach ($points as $point) {
                $coords[] = number_format($x($point['date']), 1, '.', '') . ',' . number_format($y($point['price']), 1, '.', '');
            }

            $lines .= '<polyline fill="none" stroke="' . esc_attr($color) . '" stroke-width="2" points="' . esc_attr(implode(' ', $coords)) . '" />';

            $label = $brands[$symbol] ?? $symbol;
            $legend .= '<span style="display:inline-block;margin-right:1rem">'
                . '<span style="display:inline-block;width:0.75rem;height:0.75rem;background:' . esc_attr($color) . ';border-radius:2px;margin-right:0.25rem"></span>'
                . esc_html($label)
                . '</span>';
        }
        ?>
        <figure class="kk-gold-chart" style="margin:0 0 1rem">
            <svg viewBox="0 0 <?php echo (int) self::CHART_WIDTH; ?> <?php echo (int) self::CHART_HEIGHT; ?>" width="100%" role="img" aria-label="<?php esc_attr_e('Gold price history per brand', 'koperasikita'); ?>">
                <line x1="<?php echo (int) self::CHART_PAD_LEFT; ?>" y1="<?php echo (int) self::CHART_PAD_TOP; ?>" x2="<?php echo (int) self::CHART_PAD_LEFT; ?>" y2="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" stroke="#d1d5db" />
                <line x1="<?php echo (int) self::CHART_PAD_LEFT; ?>" y1="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" x2="<?php echo (int) (self::CHART_WIDTH - self::CHART_PAD_RIGHT); ?>" y2="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" stroke="#d1d5db" />
                <text x="<?php echo (int) (self::CHART_PAD_LEFT - 8); ?>" y="<?php echo (int) (self::CHART_PAD_TOP + 4); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html(number_format_i18n((int) round($max))); ?></text>
                <text x="<?php echo (int) (self::CHART_PAD_LEFT - 8); ?>" y="<?php echo (int) (self::CHART_HEIGHT - self::CHART_PAD_BOTTOM); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html(number_format_i18n((int) round($min))); ?></text>
                <text x="<?php echo (int) self::CHART_PAD_LEFT; ?>" y="<?php echo (int) (self::CHART_HEIGHT - 8); ?>" font-size="11" fill="#6b7280"><?php echo esc_html($firstDate); ?></text>
                <text x="<?php echo (int) (self::CHART_WIDTH - self::CHART_PAD_RIGHT); ?>" y="<?php echo (int) (self::CHART_HEIGHT - 8); ?>" text-anchor="end" font-size="11" fill="#6b7280"><?php echo esc_html($lastDate); ?></text>
                <?php echo $lines; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- coordinates and colours are escaped above. ?>
            </svg>
            <figcaption><?php echo $legend; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- labels and colours are escaped above. ?></figcaption>
        </figure>
        <?php
    }

    /**
     * History list: latest per brand first (the table order is
     * recorded_date DESC), with date, brand, sell and buyback
     * price per gram in IDR. The rows arrive already paged from
     * the query - this only renders them.
     *
     * @param array<int, array{provider: string, symbol: string, sell_price: float, buyback_price: float|null, currency: string, recorded_date: string|null, fetched_at: int}> $history
     * @param array<string, string> $brands Slug => label.
     */
    private static function renderHistoryTable(array $history, array $brands, bool $filtered = false): void
    {
        if ($history === []) {
            echo '<p>' . esc_html__($filtered ? 'No prices match the selected filters.' : 'No prices stored yet. Run a sync to fill the table.', 'koperasikita') . '</p>';

            return;
        }

        $rows = '';
        foreach ($history as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            $label = $brands[$symbol] ?? $symbol;
            $sell = number_format_i18n((float) ($row['sell_price'] ?? 0), 0);
            $buyback = isset($row['buyback_price']) && $row['buyback_price'] !== null
                ? number_format_i18n((float) $row['buyback_price'], 0)
                : '-';

            $rows .= '<tr>'
                . '<td>' . esc_html((string) ($row['recorded_date'] ?? '-')) . '</td>'
                . '<td><strong>' . esc_html($label) . '</strong></td>'
                . '<td>' . esc_html(Gold::providerLabel((string) ($row['provider'] ?? ''))) . '</td>'
                . '<td>' . esc_html($sell) . '</td>'
                . '<td>' . esc_html($buyback) . '</td>'
                . '</tr>';
        }
        ?>
        <div class="kk-gold-table-wrap">
        <table class="widefat striped kk-gold-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Brand', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Provider', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Sell (per gram)', 'koperasikita'); ?></th>
                    <th><?php esc_html_e('Buyback (per gram)', 'koperasikita'); ?></th>
                </tr>
            </thead>
            <tbody><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped above. ?></tbody>
        </table>
        </div>
        <?php
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Agenda;

/**
 * The Agenda -> Locations admin screen: a post-type-style page for
 * the reusable places the Pick Location popup offers.
 *
 * The list is a WP_List_Table (checkboxes, bulk delete, search,
 * sortable name, pagination) and each location has its own edit
 * screen with the fields the place needs: name, address, latitude,
 * longitude and description. Rows are stored in the
 * `kk_agenda_locations` option with a stable id each, so an edit /
 * delete link never shifts when another row is removed. The popup
 * reads the same option through Agenda::savedLocations(), so nothing
 * about the picker changes.
 */
final class AgendaLocationsSettings
{
    /**
     * The result of the early action handling (admin_init): the
     * notice / error / submitted values the page render shows.
     * Set by maybeHandleActions(); renderSettingsPage() only reads
     * it, never handles a submission itself.
     *
     * @var array{notice: string, error: string, id: string, values: array{name: string, address: string, lat: string, lng: string, description: string}|null}|null
     */
    private static ?array $pendingResult = null;

    public static function register(): void
    {
        // The page itself lives under the single "Koperasi Kita"
        // top-level menu (AdminMenu); only the settings
        // registration stays here.
        add_action('admin_init', [self::class, 'registerSettings']);
        // The save / delete submissions are handled here, before any
        // output: the page callback runs after the admin header, so a
        // redirect attempted there fails (headers already sent) and
        // the exit leaves a blank content area after saving.
        add_action('admin_init', [self::class, 'maybeHandleActions']);
    }

    public static function registerSettings(): void
    {
        register_setting(Agenda::LOCATIONS_OPTION, Agenda::LOCATIONS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeLocations'],
            'default' => [],
        ]);
    }

    /**
     * One location row, cleaned. A row without a name or with invalid
     * coordinates is dropped, so a half-filled row can never reach
     * the picker; the description is truncated to
     * Agenda::LOCATION_DESC_MAX instead of dropping the row. The
     * stable id is kept when the row already has one - rows saved
     * before ids existed get theirs from locations().
     *
     * @param mixed $row Raw row.
     * @return array{id?: string, name: string, address: string, lat: float, lng: float, description: string}|null
     */
    public static function sanitizeLocationRow($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $name = sanitize_text_field((string) ($row['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $lat = Agenda::sanitizeLatitude($row['lat'] ?? null);
        $lng = Agenda::sanitizeLongitude($row['lng'] ?? null);

        if ($lat === null || $lng === null) {
            return null;
        }

        $clean = [
            'name' => $name,
            'address' => sanitize_text_field((string) ($row['address'] ?? '')),
            'lat' => $lat,
            'lng' => $lng,
            'description' => mb_substr(sanitize_text_field((string) ($row['description'] ?? '')), 0, Agenda::LOCATION_DESC_MAX),
        ];

        if (isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
            $clean['id'] = sanitize_key($row['id']);
        }

        return $clean;
    }

    /**
     * Sanitizer for the whole locations option.
     *
     * @param mixed $input Raw submitted value.
     * @return array<int, array{id?: string, name: string, address: string, lat: float, lng: float, description: string}>
     */
    public static function sanitizeLocations($input): array
    {
        $clean = [];

        if (!is_array($input)) {
            return $clean;
        }

        foreach ($input as $row) {
            $cleanRow = self::sanitizeLocationRow($row);

            if ($cleanRow !== null) {
                $clean[] = $cleanRow;
            }
        }

        return $clean;
    }

    /**
     * All stored locations, each with a stable id. Rows saved before
     * ids existed get one minted here and persisted once, so edit /
     * delete links never shift when a row is removed.
     *
     * @return array<int, array{id: string, name: string, address: string, lat: float, lng: float, description: string}>
     */
    public static function locations(): array
    {
        $locations = self::sanitizeLocations(get_option(Agenda::LOCATIONS_OPTION, []));
        $changed = false;

        foreach ($locations as &$row) {
            if (!isset($row['id']) || $row['id'] === '') {
                $row['id'] = 'loc_' . wp_generate_uuid4();
                $changed = true;
            }
        }

        unset($row);

        if ($changed) {
            update_option(Agenda::LOCATIONS_OPTION, array_values($locations));
        }

        return array_values($locations);
    }

    /** @return array{id: string, name: string, address: string, lat: float, lng: float, description: string}|null */
    public static function findLocation(string $id): ?array
    {
        foreach (self::locations() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Handle the save / delete submissions before any output, so the
     * redirect after a write can actually be sent. Runs on
     * admin_init, only on this page; the render only reads the
     * stored result. A failed save stores its error plus the
     * submitted values, so the form re-opens with them.
     */
    public static function maybeHandleActions(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page is display-only.
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

        if ($page !== Agenda::LOCATIONS_PAGE_SLUG) {
            return;
        }

        self::$pendingResult = self::handleActions();
    }

    /** The ?saved=1 / ?deleted=N notice after a redirect, with no writes. */
    private static function flagNotices(): array
    {
        $empty = ['notice' => '', 'error' => '', 'id' => '', 'values' => null];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved/deleted flags are display-only.
        if (isset($_GET['saved']) && $_GET['saved'] === '1') {
            $empty['notice'] = __('Location saved.', 'koperasikita');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved/deleted flags are display-only.
        if (isset($_GET['deleted'])) {
            $empty['notice'] = __('Location deleted.', 'koperasikita');
        }

        return $empty;
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        // The submission was already handled on admin_init (before
        // any output); the render only reads the stored result. If
        // the early handler did not run, only the redirect flags are
        // read here - a submission is never processed at render time.
        $result = self::$pendingResult ?? self::flagNotices();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action/id are display-only.
        $id = isset($_GET['id']) ? sanitize_key((string) wp_unslash($_GET['id'])) : '';

        // A failed save re-opens the form with the submitted values
        // instead of dropping the editor back to the list.
        if ($result['error'] !== '') {
            $action = $result['id'] !== '' ? 'edit' : 'new';
            $id = $result['id'];
        }

        $pageUrl = admin_url('admin.php?page=' . Agenda::LOCATIONS_PAGE_SLUG);
        ?>
        <div class="wrap kk-locations-page">
            <h1 class="kk-locations-title"><?php esc_html_e('Locations', 'koperasikita'); ?></h1>
            <?php if ($result['notice'] !== '') : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html($result['notice']); ?></p></div>
            <?php endif; ?>
            <?php if ($result['error'] !== '') : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($result['error']); ?></p></div>
            <?php endif; ?>
            <?php if ($action === 'new' || ($action === 'edit' && $id !== '')) : ?>
                <?php self::renderForm($action === 'edit' ? $id : '', $pageUrl, $result['values']); ?>
            <?php else : ?>
                <section class="kk-locations-card">
                    <p class="kk-locations-card-actions">
                        <a href="<?php echo esc_url(add_query_arg('action', 'new', $pageUrl)); ?>" class="kk-locations-button kk-locations-button--primary">
                            <?php esc_html_e('Add New Location', 'koperasikita'); ?>
                        </a>
                    </p>
                    <?php self::renderList($pageUrl); ?>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle the save / single delete / bulk delete submissions.
     * Successful writes redirect (PRG); a failed save returns the
     * error plus the submitted values so the form re-opens with them.
     *
     * @return array{notice: string, error: string, id: string, values: array{name: string, address: string, lat: string, lng: string, description: string}|null}
     */
    private static function handleActions(): array
    {
        $pageUrl = admin_url('admin.php?page=' . Agenda::LOCATIONS_PAGE_SLUG);
        $empty = ['notice' => '', 'error' => '', 'id' => '', 'values' => null];

        $flags = self::flagNotices();
        if ($flags['notice'] !== '') {
            return $flags;
        }

        if (isset($_POST['kk_agenda_location_nonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_agenda_location', 'kk_agenda_location_nonce');

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
            $raw = wp_unslash($_POST);
            $id = isset($raw['kk_location_id']) ? sanitize_key((string) $raw['kk_location_id']) : '';
            $values = [
                'name' => isset($raw['kk_location_name']) ? sanitize_text_field((string) $raw['kk_location_name']) : '',
                'address' => isset($raw['kk_location_address']) ? sanitize_text_field((string) $raw['kk_location_address']) : '',
                'lat' => isset($raw['kk_location_lat']) ? sanitize_text_field((string) $raw['kk_location_lat']) : '',
                'lng' => isset($raw['kk_location_lng']) ? sanitize_text_field((string) $raw['kk_location_lng']) : '',
                'description' => isset($raw['kk_location_description']) ? sanitize_text_field((string) $raw['kk_location_description']) : '',
            ];

            $lat = Agenda::sanitizeLatitude($values['lat']);
            $lng = Agenda::sanitizeLongitude($values['lng']);

            if ($values['name'] === '' || $lat === null || $lng === null) {
                $empty['error'] = __('A name and valid coordinates are required.', 'koperasikita');
                $empty['id'] = $id;
                $empty['values'] = $values;

                return $empty;
            }

            $locations = self::locations();
            $row = [
                'id' => $id !== '' ? $id : 'loc_' . wp_generate_uuid4(),
                'name' => $values['name'],
                'address' => $values['address'],
                'lat' => $lat,
                'lng' => $lng,
                'description' => mb_substr($values['description'], 0, Agenda::LOCATION_DESC_MAX),
            ];

            $found = false;
            foreach ($locations as &$existing) {
                if ($existing['id'] === $row['id']) {
                    $existing = $row;
                    $found = true;
                    break;
                }
            }
            unset($existing);

            if (!$found) {
                $locations[] = $row;
            }

            update_option(Agenda::LOCATIONS_OPTION, array_values($locations));

            wp_safe_redirect(add_query_arg('saved', '1', $pageUrl));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- single delete uses a nonce in the URL.
        if (isset($_GET['kk_location_delete']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('kk_location_delete', '_wpnonce');

            $id = sanitize_key((string) wp_unslash($_GET['kk_location_delete']));
            $removed = self::deleteLocations([$id]);

            wp_safe_redirect(add_query_arg('deleted', (string) max(1, $removed), $pageUrl));
            exit;
        }

        // Bulk delete from the list table: action=delete + location[]
        // + the bulk-locations nonce the table itself renders.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk delete uses the table nonce.
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['location']) && is_array($_GET['location'])) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
            }

            check_admin_referer('bulk-locations');

            $ids = array_values(array_map('sanitize_key', wp_unslash($_GET['location'])));
            $removed = self::deleteLocations($ids);

            wp_safe_redirect(add_query_arg('deleted', (string) max(1, $removed), $pageUrl));
            exit;
        }

        return $empty;
    }

    /** Remove the locations with these ids. Returns how many were removed. */
    private static function deleteLocations(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $locations = self::locations();
        $before = count($locations);
        $locations = array_values(array_filter(
            $locations,
            static fn(array $row): bool => !in_array($row['id'], $ids, true)
        ));

        if (count($locations) !== $before) {
            update_option(Agenda::LOCATIONS_OPTION, $locations);
        }

        return $before - count($locations);
    }

    /** The post-type-style list: search, bulk delete, pagination. */
    private static function renderList(string $pageUrl): void
    {
        $table = new AgendaLocationsTable(self::locations());
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url($pageUrl); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(Agenda::LOCATIONS_PAGE_SLUG); ?>">
            <?php $table->search_box(__('Search Locations', 'koperasikita'), 'kk-location'); ?>
            <div class="kk-locations-table-wrap">
                <?php $table->display(); ?>
            </div>
        </form>
        <p class="description kk-locations-note">
            <?php esc_html_e('Save the places you use often. They appear in the Pick Location popup, so you can reuse a place instead of searching for it again.', 'koperasikita'); ?>
        </p>
        <?php
    }

    /**
     * The add / edit screen: one location, with the fields the place
     * needs. $values (a failed save) overrides the stored row.
     *
     * @param array{name: string, address: string, lat: string, lng: string, description: string}|null $values
     */
    private static function renderForm(string $id, string $pageUrl, ?array $values): void
    {
        $stored = $id !== '' ? self::findLocation($id) : null;

        if ($id !== '' && $stored === null && $values === null) {
            ?>
            <section class="kk-locations-card">
                <div class="notice notice-error inline"><p><?php esc_html_e('Location not found.', 'koperasikita'); ?></p></div>
                <p><a href="<?php echo esc_url($pageUrl); ?>" class="kk-locations-button"><?php esc_html_e('Back to Locations', 'koperasikita'); ?></a></p>
            </section>
            <?php
            return;
        }

        $form = $values ?? [
            'name' => $stored['name'] ?? '',
            'address' => $stored['address'] ?? '',
            'lat' => isset($stored['lat']) ? (string) $stored['lat'] : '',
            'lng' => isset($stored['lng']) ? (string) $stored['lng'] : '',
            'description' => $stored['description'] ?? '',
        ];
        $coords = ($form['lat'] !== '' && $form['lng'] !== '')
            ? $form['lat'] . ', ' . $form['lng']
            : __('No pin placed', 'koperasikita');
        // No empty id on the add URL: the add form posts to
        // ?action=new only, so a failed save re-opens at a clean URL.
        $formAction = $id !== ''
            ? add_query_arg(['action' => 'edit', 'id' => $id], $pageUrl)
            : add_query_arg('action', 'new', $pageUrl);
        ?>
        <section class="kk-locations-card">
        <h2 class="kk-locations-card-title"><?php echo $id !== '' ? esc_html__('Edit Location', 'koperasikita') : esc_html__('Add New Location', 'koperasikita'); ?></h2>
        <form method="post" action="<?php echo esc_url($formAction); ?>">
            <?php wp_nonce_field('kk_agenda_location', 'kk_agenda_location_nonce'); ?>
            <input type="hidden" name="kk_location_id" value="<?php echo esc_attr($id); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="kk-location-search"><?php esc_html_e('Search for a place…', 'koperasikita'); ?></label></th>
                    <td>
                        <div
                            class="kk-agenda-location-search"
                            data-kk-agenda-location-search
                            data-kk-agenda-location-context="location-form"
                            data-kk-agenda-saved-locations="[]"
                            data-kk-agenda-searching="<?php esc_attr_e('Searching…', 'koperasikita'); ?>"
                            data-kk-agenda-no-results="<?php esc_attr_e('No places found', 'koperasikita'); ?>"
                            data-kk-agenda-search-unavailable="<?php esc_attr_e('Search is unavailable right now.', 'koperasikita'); ?>"
                            data-kk-agenda-saved-label="<?php esc_attr_e('Saved location', 'koperasikita'); ?>"
                            data-kk-agenda-place-label="<?php esc_attr_e('Places', 'koperasikita'); ?>"
                        >
                            <input
                                type="search"
                                id="kk-location-search"
                                class="regular-text"
                                placeholder="<?php esc_attr_e('Search for a place…', 'koperasikita'); ?>"
                                autocomplete="off"
                                role="combobox"
                                aria-expanded="false"
                                aria-controls="kk-location-search-results"
                                aria-autocomplete="list"
                            >
                            <ul class="kk-agenda-location-results" id="kk-location-search-results" role="listbox" hidden></ul>
                        </div>
                        <p class="description"><?php esc_html_e('Type to search saved locations and places. Picking one fills the fields below.', 'koperasikita'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-location-name"><?php esc_html_e('Name', 'koperasikita'); ?></label></th>
                    <td><input type="text" id="kk-location-name" name="kk_location_name" value="<?php echo esc_attr($form['name']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-location-address"><?php esc_html_e('Address', 'koperasikita'); ?></label></th>
                    <td><input type="text" id="kk-location-address" name="kk_location_address" value="<?php echo esc_attr($form['address']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-location-lat"><?php esc_html_e('Latitude', 'koperasikita'); ?></label></th>
                    <td><input type="number" id="kk-location-lat" name="kk_location_lat" value="<?php echo esc_attr($form['lat']); ?>" step="any" min="-90" max="90" inputmode="decimal" class="regular-text" data-kk-agenda-lat required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-location-lng"><?php esc_html_e('Longitude', 'koperasikita'); ?></label></th>
                    <td><input type="number" id="kk-location-lng" name="kk_location_lng" value="<?php echo esc_attr($form['lng']); ?>" step="any" min="-180" max="180" inputmode="decimal" class="regular-text" data-kk-agenda-lng required></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Map', 'koperasikita'); ?></th>
                    <td>
                        <?php /* The Leaflet map picker: the map is a
                         * picker - a click places the pin and a drag
                         * moves it, writing the Latitude / Longitude
                         * fields above (centred on the default location
                         * with no pin until both hold a coordinate).
                         * The map container is display: none until the
                         * script marks it ready, so without JavaScript
                         * there is no empty box. */ ?>
                        <div class="kk-agenda-map-picker" data-kk-agenda-map-picker data-kk-agenda-default-lat="<?php echo esc_attr((string) Agenda::DEFAULT_LAT); ?>" data-kk-agenda-default-lng="<?php echo esc_attr((string) Agenda::DEFAULT_LNG); ?>" data-kk-agenda-no-pin="<?php esc_attr_e('No pin placed', 'koperasikita'); ?>">
                            <div class="kk-agenda-map" data-kk-location-preview-map></div>
                            <p class="kk-agenda-coords" data-kk-agenda-coords><?php echo esc_html($coords); ?></p>
                            <p class="description"><?php esc_html_e('Click the map to place the marker, or drag it.', 'koperasikita'); ?></p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kk-location-description"><?php esc_html_e('Location description', 'koperasikita'); ?></label></th>
                    <td>
                        <textarea id="kk-location-description" name="kk_location_description" rows="3" maxlength="<?php echo (int) Agenda::LOCATION_DESC_MAX; ?>" class="large-text" data-kk-agenda-location-desc><?php echo esc_textarea($form['description']); ?></textarea>
                        <p class="description"><?php esc_html_e('Shown in the map marker popup for the event.', 'koperasikita'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="submit" id="submit" class="kk-locations-button kk-locations-button--primary" value="<?php echo esc_attr($id !== '' ? __('Update Location', 'koperasikita') : __('Add Location', 'koperasikita')); ?>"></p>
            <a href="<?php echo esc_url($pageUrl); ?>" class="kk-locations-button"><?php esc_html_e('Cancel', 'koperasikita'); ?></a>
        </form>
        </section>
        <?php
    }
}

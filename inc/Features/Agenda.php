<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Agenda custom post type with the contributor review workflow.
 *
 * An agenda item is a dated cooperative event: it always carries a start
 * date and time, an optional end time on the same day, and a location.
 * The post type uses the standard `post` capability type with mapped meta
 * capabilities, so the WordPress roles already mean the right thing:
 * contributors can create and edit their own agenda items but cannot
 * publish them, while editors and administrators review and publish.
 *
 * Contributors submit through the `[kk_agenda_submit]` shortcode. The form
 * never publishes: every submission is stored with status `pending`, no
 * matter who submits it, and the author is always the logged-in user -
 * never a value taken from the request. Pending items are invisible to the
 * public (WordPress excludes them from public queries and serves a 404 on
 * their single URL) until an editor publishes them from the admin.
 *
 * The submission form is protected the same way a subscribe form is:
 * a nonce, a capability check, sanitisation of every field, a honeypot
 * field, and a per-IP rate limit of three attempts per hour.
 *
 * When the feature is disabled in the Customizer nothing here hooks in at
 * all: no post type, no shortcode, no meta box, no frontend output.
 */
final class Agenda
{
    public const POST_TYPE = 'agenda';

    public const SHORTCODE = 'kk_agenda_submit';

    public const NONCE_ACTION = 'kk_agenda_submit';

    public const META_START = '_kk_agenda_start';

    public const META_END = '_kk_agenda_end';

    public const META_LOCATION = '_kk_agenda_location';

    public const META_START_TS = '_kk_agenda_start_ts';

    /** Map pin coordinates, stored alongside the venue text. */
    public const META_LAT = '_kk_agenda_lat';

    public const META_LNG = '_kk_agenda_lng';

    /** Per-event venue description/notes, shown in the map marker popup. */
    public const META_LOCATION_DESC = '_kk_agenda_location_desc';

    /** Saved reusable locations (option `kk_agenda_locations`). */
    public const LOCATIONS_OPTION = 'kk_agenda_locations';

    /** Saved locations page slug (under the Agenda menu). */
    public const LOCATIONS_PAGE_SLUG = 'kk-agenda-locations';

    /** A location description is silently truncated past this length. */
    public const LOCATION_DESC_MAX = 200;

    /** Default map centre (Padang, same as the weather default). */
    public const DEFAULT_LAT = -0.9471;

    public const DEFAULT_LNG = 100.4172;

    /** Maximum submissions from one IP address within the rate window. */
    public const RATE_LIMIT_MAX = 3;

    /** Rate-limit window in seconds (one hour). */
    public const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

    /**
     * Sort key stored for an item without a date (2100-01-01). The archive
     * orders by this key ascending, so a dateless item sinks to the end
     * instead of floating to the top with a timestamp of 0. Readers never
     * see the sentinel: getMeta() reports start_ts 0 for a dateless item.
     */
    public const NO_DATE_TS = 4102444800;

    public static function isEnabled(): bool
    {
        return Features::isEnabled('agenda');
    }

    /** Location picker positions, in display order. */
    public static function locationPositions(): array
    {
        return ['below-info', 'above-info', 'below-article', 'below-thumbnail', 'before-article'];
    }

    /** Whether the event Location picker is shown on single agenda pages. */
    public static function locationEnabled(): bool
    {
        return (bool) get_theme_mod('kk_agenda_location_enabled', true);
    }

    /** Where the event Location picker is shown on single agenda pages. */
    public static function locationPosition(): string
    {
        $position = (string) get_theme_mod('kk_agenda_location_position', 'below-info');

        return in_array($position, self::locationPositions(), true) ? $position : 'below-info';
    }

    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        // We are already running on 'init' (Features::register is hooked
        // there), so register the post type and meta directly. Hooking them
        // to 'init' again at the same priority would run too late: a
        // callback added to the hook that is currently executing, at the
        // priority that is currently running, is not picked up.
        self::registerPostType();
        self::registerMeta();
        \KoperasiKita\Admin\AgendaLocationsSettings::register();
        add_action('init', [self::class, 'handleSubmission'], 20);
        add_action('after_switch_theme', 'flush_rewrite_rules');
        add_action('add_meta_boxes_' . self::POST_TYPE, [self::class, 'registerMetaBox']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'saveMetaBox'], 10, 2);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'ensureMeta'], 20);
        // Place search for the Event Details location field (and the
        // Locations admin form): saved locations first, then the
        // geocoder. Only users who can edit agenda items may search -
        // contributors included, anonymous visitors never.
        \KoperasiKita\Api\Ajax::add('kk_agenda_place_search', [self::class, 'ajaxSearchPlaces'], false, 'edit_posts');
        // Title search for the Agenda widget's "Link to" field:
        // published posts and pages by title, nothing else.
        \KoperasiKita\Api\Ajax::add('kk_agenda_link_search', [\KoperasiKita\Widgets\AgendaWidget::class, 'ajaxSearchLink'], false, 'edit_posts');
        // The Pick Location popup is rendered once at the end of the
        // admin page (never inside the meta box): a position:fixed
        // popup inside the editor sidebar can be trapped by an ancestor
        // stacking context, and it must sit above the editor but below
        // the admin menu and admin bar (see the popup stylesheet).
        add_action('admin_footer', [self::class, 'renderPickLocationPopup']);
        add_action('pre_get_posts', [self::class, 'orderArchive']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'adminColumns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'renderAdminColumn'], 10, 2);
        add_shortcode(self::SHORTCODE, [self::class, 'renderShortcode']);
    }

    public static function registerPostType(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Agenda', 'koperasikita'),
                    'singular_name' => __('Agenda Item', 'koperasikita'),
                    'add_new' => __('Add New', 'koperasikita'),
                    'add_new_item' => __('Add New Agenda Item', 'koperasikita'),
                    'edit_item' => __('Edit Agenda Item', 'koperasikita'),
                    'new_item' => __('New Agenda Item', 'koperasikita'),
                    'view_item' => __('View Agenda Item', 'koperasikita'),
                    'view_items' => __('View Agenda', 'koperasikita'),
                    'search_items' => __('Search Agenda', 'koperasikita'),
                    'not_found' => __('No agenda items found.', 'koperasikita'),
                    'not_found_in_trash' => __('No agenda items found in Trash.', 'koperasikita'),
                    'all_items' => __('All Agenda Items', 'koperasikita'),
                    'archives' => __('Agenda', 'koperasikita'),
                    'attributes' => __('Agenda Item Attributes', 'koperasikita'),
                    'insert_into_item' => __('Insert into agenda item', 'koperasikita'),
                    'uploaded_to_this_item' => __('Uploaded to this agenda item', 'koperasikita'),
                    'filter_items_list' => __('Filter agenda items', 'koperasikita'),
                    'items_list_navigation' => __('Agenda items list navigation', 'koperasikita'),
                    'items_list' => __('Agenda items list', 'koperasikita'),
                    'item_published' => __('Agenda item published.', 'koperasikita'),
                    'item_published_privately' => __('Agenda item published privately.', 'koperasikita'),
                    'item_reverted_to_draft' => __('Agenda item reverted to draft.', 'koperasikita'),
                    'item_scheduled' => __('Agenda item scheduled.', 'koperasikita'),
                    'item_updated' => __('Agenda item updated.', 'koperasikita'),
                ],
                'description' => __('Dated cooperative events: meetings, gatherings and activities.', 'koperasikita'),
                'public' => true,
                'has_archive' => true,
                'show_in_rest' => true,
                'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
                'menu_position' => 21,
                'menu_icon' => 'dashicons-calendar-alt',
                'rewrite' => ['slug' => 'agenda'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]
        );
    }

    /**
     * Post meta for the event details. The start/end/location strings are
     * what the templates print; the Unix timestamp exists only so the
     * archive can order items chronologically.
     */
    public static function registerMeta(): void
    {
        $auth = static function (): bool {
            return current_user_can('edit_posts');
        };

        register_post_meta(
            self::POST_TYPE,
            self::META_START,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => [self::class, 'sanitizeDatetime'],
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_END,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => [self::class, 'sanitizeDatetime'],
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_LOCATION,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_START_TS,
            [
                'type' => 'integer',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'absint',
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_LAT,
            [
                'type' => 'number',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => [self::class, 'sanitizeLatitude'],
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_LNG,
            [
                'type' => 'number',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => [self::class, 'sanitizeLongitude'],
                'auth_callback' => $auth,
            ]
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_LOCATION_DESC,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => $auth,
            ]
        );
    }

    /**
     * Sanitizer for a `Y-m-d H:i` datetime string. Anything that does not
     * parse to a real date and time becomes an empty string, so bad input
     * can never be stored.
     */
    public static function sanitizeDatetime($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return '';
        }

        $parsed = date_create_immutable_from_format('Y-m-d H:i', $value, wp_timezone());

        if ($parsed === false) {
            return '';
        }

        // date_create_immutable_from_format accepts impossible dates by
        // rolling them over (31 February becomes 3 March), so re-format and
        // compare to prove the original date actually exists.
        if ($parsed->format('Y-m-d H:i') !== $value) {
            return '';
        }

        return $value;
    }

    /**
     * Sanitizer for one map coordinate. Anything that is not a finite
     * number inside the range becomes null, so bad input can never be
     * stored - an invalid pin is dropped, never saved.
     *
     * @param mixed $value Raw input.
     */
    public static function sanitizeCoordinate($value, float $min, float $max): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if (!is_numeric($value)) {
                return null;
            }

            $value = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $value = (float) $value;
        } else {
            return null;
        }

        if (!is_finite($value) || $value < $min || $value > $max) {
            return null;
        }

        return $value;
    }

    /** @param mixed $value Raw input. */
    public static function sanitizeLatitude($value): ?float
    {
        return self::sanitizeCoordinate($value, -90.0, 90.0);
    }

    /** @param mixed $value Raw input. */
    public static function sanitizeLongitude($value): ?float
    {
        return self::sanitizeCoordinate($value, -180.0, 180.0);
    }

    /**
     * Event details for one agenda item, with every value already cleaned.
     * An item without a start date reports start_ts 0, even though the
     * stored sort key is the far-future NO_DATE_TS sentinel (see saveMeta):
     * consumers must never have to know about the sentinel.
     *
     * The meta is read duplicate-tolerantly: every row for each key is
     * considered and the first usable one wins. An item imported from a
     * WXR file can carry two rows per key - the empty rows ensureMeta()
     * wrote during wp_insert_post, plus the real rows the importer added
     * afterwards - and get_post_meta(..., true) would return the first
     * (empty) row, hiding the event's date entirely.
     *
     * @return array{start: string, end: string, location: string, start_ts: int, lat: float|null, lng: float|null, location_desc: string}
     */
    public static function getMeta(int $postId): array
    {
        $start = self::firstValidDatetime(get_post_meta($postId, self::META_START, false));
        $end = self::firstValidDatetime(get_post_meta($postId, self::META_END, false));
        $location = self::firstNonEmptyText(get_post_meta($postId, self::META_LOCATION, false));
        $startTs = self::firstRealTimestamp(get_post_meta($postId, self::META_START_TS, false));
        $lat = self::firstValidCoordinate(get_post_meta($postId, self::META_LAT, false), -90.0, 90.0);
        $lng = self::firstValidCoordinate(get_post_meta($postId, self::META_LNG, false), -180.0, 180.0);
        $locationDesc = self::firstNonEmptyText(get_post_meta($postId, self::META_LOCATION_DESC, false));

        if ($start === '') {
            return [
                'start' => $start,
                'end' => $end,
                'location' => $location,
                'start_ts' => 0,
                'lat' => $lat,
                'lng' => $lng,
                'location_desc' => $locationDesc,
            ];
        }

        // Older items (or items saved before the timestamp existed) only
        // carry the string: derive the timestamp from it instead of
        // leaving the item unsortable.
        if ($startTs <= 0) {
            $parsed = date_create_immutable_from_format('Y-m-d H:i', $start, wp_timezone());
            $startTs = $parsed !== false ? (int) $parsed->getTimestamp() : 0;
        }

        return [
            'start' => $start,
            'end' => $end,
            'location' => $location,
            'start_ts' => $startTs,
            'lat' => $lat,
            'lng' => $lng,
            'location_desc' => $locationDesc,
        ];
    }

    /**
     * AJAX: search places for the agenda location picker.
     *
     * Saved locations match first (instant, no network), then the
     * geocoder - Open-Meteo through Weather::search() when the weather
     * module is on, Nominatim direct when it is off, so the picker
     * never goes silent just because weather is disabled. Geocoded
     * rows that duplicate a saved location (same coordinates) are
     * dropped: the saved row already carries the better name and
     * description. Called through Api\Ajax, which already verified
     * the nonce and the edit_posts capability.
     *
     * @return array{results: array<int, array{source: string, id: string, name: string, address: string, latitude: float, longitude: float, description: string}>}
     */
    public static function ajaxSearchPlaces(): array
    {
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        if (mb_strlen($query) < 2) {
            return ['results' => []];
        }

        $results = [];
        $needle = mb_strtolower($query);

        foreach (self::savedLocations() as $saved) {
            if (count($results) >= 5) {
                break;
            }

            $haystack = mb_strtolower($saved['name'] . ' ' . $saved['address']);

            if (!str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = [
                'source' => 'saved',
                'id' => $saved['id'] ?? '',
                'name' => $saved['name'],
                'address' => $saved['address'],
                'latitude' => $saved['lat'],
                'longitude' => $saved['lng'],
                'description' => $saved['description'],
            ];
        }

        $geocoded = Features::isEnabled('weather')
            ? Weather::search($query)
            : self::searchPlacesNominatim($query);

        foreach ($geocoded as $place) {
            if (count($results) >= 8) {
                break;
            }

            if (!isset($place['name'], $place['latitude'], $place['longitude'])) {
                continue;
            }

            $latitude = (float) $place['latitude'];
            $longitude = (float) $place['longitude'];

            // A geocoded row on top of a saved place adds nothing:
            // the saved row already has the curated name, address
            // and description for these coordinates.
            $duplicate = false;

            foreach ($results as $kept) {
                if (abs($kept['latitude'] - $latitude) < 0.01 && abs($kept['longitude'] - $longitude) < 0.01) {
                    $duplicate = true;
                    break;
                }
            }

            if ($duplicate) {
                continue;
            }

            $results[] = [
                'source' => 'geocoded',
                'id' => '',
                'name' => sanitize_text_field((string) $place['name']),
                'address' => sanitize_text_field((string) ($place['country'] ?? '')),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'description' => '',
            ];
        }

        return ['results' => $results];
    }

    /**
     * Geocode a place name through Nominatim (OpenStreetMap), the
     * fallback when the weather module - and with it the Open-Meteo
     * geocoder - is off. Server-side, so the browser never talks to
     * a third party directly. The first comma segment is the place
     * name, the rest is the address; a failed fetch answers with an
     * empty list, never an error, so the saved locations still show.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float, country: string}>
     */
    private static function searchPlacesNominatim(string $query): array
    {
        $url = add_query_arg(
            [
                'format' => 'json',
                'limit' => 5,
                'accept-language' => 'id',
                'q' => $query,
            ],
            'https://nominatim.openstreetmap.org/search'
        );

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'user-agent' => 'KoperasiKita/1.0 (+' . home_url() . ')',
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return [];
        }

        $results = [];

        foreach ($body as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'], $row['display_name'])) {
                continue;
            }

            $lat = Agenda::sanitizeLatitude($row['lat']);
            $lng = Agenda::sanitizeLongitude($row['lon']);

            if ($lat === null || $lng === null) {
                continue;
            }

            $parts = array_map('trim', explode(',', (string) $row['display_name']));

            $results[] = [
                'name' => sanitize_text_field($parts[0] ?? ''),
                'latitude' => $lat,
                'longitude' => $lng,
                'country' => sanitize_text_field(implode(', ', array_slice($parts, 1))),
            ];
        }

        return $results;
    }

    /**
     * Append one place to the shared saved locations, or return the
     * existing row when the name (or the coordinates) is already
     * saved - the picker never creates a duplicate. Returns null
     * when the row cannot be saved (empty name, invalid coordinates).
     * Only called for users who may manage the shared list; see
     * saveMetaBox.
     *
     * @return array{id: string, name: string, address: string, lat: float, lng: float, description: string}|null
     */
    public static function addSavedLocation(string $name, string $address, ?float $lat, ?float $lng, string $description = ''): ?array
    {
        $row = \KoperasiKita\Admin\AgendaLocationsSettings::sanitizeLocationRow([
            'name' => $name,
            'address' => $address,
            'lat' => $lat,
            'lng' => $lng,
            'description' => $description,
        ]);

        if ($row === null) {
            return null;
        }

        $locations = \KoperasiKita\Admin\AgendaLocationsSettings::locations();

        foreach ($locations as $existing) {
            if (
                mb_strtolower($existing['name']) === mb_strtolower($row['name'])
                || (abs($existing['lat'] - $row['lat']) < 0.01 && abs($existing['lng'] - $row['lng']) < 0.01)
            ) {
                return $existing;
            }
        }

        $row['id'] = 'loc_' . wp_generate_uuid4();
        $locations[] = $row;
        update_option(self::LOCATIONS_OPTION, array_values($locations));

        return $row;
    }

    /**
     * The saved reusable locations, already cleaned: rows with an empty
     * name or invalid coordinates are dropped, so a half-filled row can
     * never reach the picker.
     *
     * @return array<int, array{id?: string, name: string, address: string, lat: float, lng: float, description: string}>
     */
    public static function savedLocations(): array
    {
        $option = get_option(self::LOCATIONS_OPTION, []);

        if (!is_array($option)) {
            return [];
        }

        return \KoperasiKita\Admin\AgendaLocationsSettings::sanitizeLocations($option);
    }

    /**
     * First meta row that parses to a real datetime, or ''. The rows
     * come back oldest first, so an empty row written during an import
     * is skipped in favour of the real value added afterwards.
     *
     * @param array<int, mixed> $rows
     */
    private static function firstValidDatetime(array $rows): string
    {
        foreach ($rows as $row) {
            $clean = self::sanitizeDatetime($row);

            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    /**
     * First meta row that survives sanitisation with something left,
     * or ''.
     *
     * @param array<int, mixed> $rows
     */
    private static function firstNonEmptyText(array $rows): string
    {
        foreach ($rows as $row) {
            $clean = sanitize_text_field((string) $row);

            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    /**
     * First timestamp row that is a real event time: a positive integer
     * that is not the NO_DATE_TS sentinel. Returns 0 when there is none
     * (the caller derives the timestamp from the start string instead).
     *
     * @param array<int, mixed> $rows
     */
    private static function firstRealTimestamp(array $rows): int
    {
        foreach ($rows as $row) {
            $ts = (int) $row;

            if ($ts > 0 && $ts !== self::NO_DATE_TS) {
                return $ts;
            }
        }

        return 0;
    }

    /**
     * First meta row that parses to a real coordinate inside the range,
     * or null when there is none.
     *
     * @param array<int, mixed> $rows
     */
    private static function firstValidCoordinate(array $rows, float $min, float $max): ?float
    {
        foreach ($rows as $row) {
            $clean = self::sanitizeCoordinate($row, $min, $max);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Save the event details for one agenda item. The timestamp is always
     * derived from the validated start string, never trusted on its own.
     * A dateless item stores the NO_DATE_TS sentinel as its sort key so
     * it orders last on the archive instead of first. Null coordinates
     * delete the stored pin: an item without a pin carries no rows. The
     * location description is truncated to LOCATION_DESC_MAX characters
     * instead of failing the save, the same spirit as an invalid pin
     * being dropped silently.
     */
    public static function saveMeta(int $postId, string $start, string $end, string $location, ?float $lat = null, ?float $lng = null, string $locationDesc = ''): void
    {
        $start = self::sanitizeDatetime($start);
        $end = self::sanitizeDatetime($end);
        $location = sanitize_text_field($location);
        $locationDesc = mb_substr(sanitize_text_field($locationDesc), 0, self::LOCATION_DESC_MAX);

        update_post_meta($postId, self::META_START, $start);
        update_post_meta($postId, self::META_END, $end);
        update_post_meta($postId, self::META_LOCATION, $location);

        if ($lat === null) {
            delete_post_meta($postId, self::META_LAT);
        } else {
            update_post_meta($postId, self::META_LAT, $lat);
        }

        if ($lng === null) {
            delete_post_meta($postId, self::META_LNG);
        } else {
            update_post_meta($postId, self::META_LNG, $lng);
        }

        if ($locationDesc === '') {
            delete_post_meta($postId, self::META_LOCATION_DESC);
        } else {
            update_post_meta($postId, self::META_LOCATION_DESC, $locationDesc);
        }

        $startTs = self::NO_DATE_TS;
        if ($start !== '') {
            $parsed = date_create_immutable_from_format('Y-m-d H:i', $start, wp_timezone());
            $startTs = $parsed !== false ? (int) $parsed->getTimestamp() : self::NO_DATE_TS;
        }

        update_post_meta($postId, self::META_START_TS, $startTs);
    }

    /**
     * Guarantee every saved agenda item carries the meta rows, even one
     * created with the meta box left empty: the archive orders by the
     * timestamp key, and WordPress drops posts without that key from a
     * meta-ordered query. Runs after the meta box save, and only writes
     * when the rows are missing entirely, so it never overwrites an
     * editor's saved details.
     *
     * During a WXR import this does nothing: wp_insert_post fires
     * save_post before the importer adds the real meta rows, so writing
     * empty rows here would leave duplicates whose first (empty) row
     * shadows the real values (see getMeta). The importer owns the meta
     * while WP_IMPORTING is set.
     */
    public static function ensureMeta(int $postId): void
    {
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }

        if (metadata_exists('post', $postId, self::META_START_TS)) {
            return;
        }

        self::saveMeta($postId, '', '', '');
    }

    /**
     * The agenda archive lists events soonest first. Items without a date
     * carry the far-future NO_DATE_TS sort key, so they sink to the end.
     *
     * The ordering is applied through the posts_orderby filter (see
     * orderByStartTsSql) instead of meta_value_num: an item imported
     * from WXR can carry duplicate timestamp rows, and the meta JOIN
     * sort key is then whichever row MySQL happens to pick. The filter
     * orders by the MIN timestamp across all rows, which is
     * deterministic and agrees with getMeta().
     */
    public static function orderArchive(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive(self::POST_TYPE)) {
            return;
        }

        self::attachSoonestFirstOrder();
    }

    /**
     * Attach the duplicate-safe soonest-first ORDER BY to the next
     * agenda query. The filter removes itself after applying once, so
     * later queries in the same request are untouched.
     */
    public static function attachSoonestFirstOrder(): void
    {
        add_filter('posts_orderby', [self::class, 'orderByStartTsSql'], 10, 2);
    }

    /**
     * posts_orderby callback for agenda queries: the main agenda
     * archive, or any query that carries the `kk_agenda_order` query
     * var (the REST /posts endpoint sets it). Every other query passes
     * through unchanged.
     */
    public static function orderByStartTsSql(string $orderby, \WP_Query $query): string
    {
        $flagged = (bool) $query->get('kk_agenda_order');
        $isArchive = $query->is_main_query() && $query->is_post_type_archive(self::POST_TYPE);

        if (!$flagged && !$isArchive) {
            return $orderby;
        }

        remove_filter('posts_orderby', [self::class, 'orderByStartTsSql'], 10);

        return self::orderByStartTsFragment();
    }

    /**
     * ORDER BY fragment: soonest event first, dateless items last,
     * deterministic tie-break by post date then ID.
     *
     * MIN over every timestamp row for the post: an imported item
     * carries the NO_DATE_TS sentinel as its first row and the real
     * timestamp as a second row - MIN picks the real one. A dateless
     * item only has the sentinel, so it sinks to the end. COALESCE
     * covers an item with no timestamp row at all (it also sorts last).
     * The key is a class constant, never user input, so interpolating
     * it is safe.
     */
    public static function orderByStartTsFragment(): string
    {
        global $wpdb;

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $sentinel = self::NO_DATE_TS;

        return "COALESCE((SELECT MIN(CAST(pm.meta_value AS UNSIGNED)) FROM {$postmeta} pm WHERE pm.post_id = {$posts}.ID AND pm.meta_key = '" . self::META_START_TS . "' AND pm.meta_value <> ''), {$sentinel}) ASC, {$posts}.post_date ASC, {$posts}.ID ASC";
    }

    // Admin: meta box and list columns

    public static function registerMetaBox(): void
    {
        add_meta_box(
            'kk-agenda-details',
            __('Event Details', 'koperasikita'),
            [self::class, 'renderMetaBox'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        $meta = self::getMeta($post->ID);
        $date = $meta['start'] !== '' ? substr($meta['start'], 0, 10) : '';
        $startTime = $meta['start'] !== '' ? substr($meta['start'], 11, 5) : '';
        $endTime = $meta['end'] !== '' ? substr($meta['end'], 11, 5) : '';
        $coords = ($meta['lat'] !== null && $meta['lng'] !== null)
            ? $meta['lat'] . ', ' . $meta['lng']
            : __('No pin placed', 'koperasikita');

        wp_nonce_field('kk_agenda_meta_box', 'kk_agenda_meta_nonce');
        ?>
        <p>
            <label for="kk-agenda-date"><strong><?php esc_html_e('Date', 'koperasikita'); ?></strong></label><br>
            <input type="date" id="kk-agenda-date" name="kk_agenda_date" value="<?php echo esc_attr($date); ?>" style="width: 100%;">
        </p>
        <p>
            <label for="kk-agenda-start-time"><strong><?php esc_html_e('Start time', 'koperasikita'); ?></strong></label><br>
            <input type="time" id="kk-agenda-start-time" name="kk_agenda_start_time" value="<?php echo esc_attr($startTime); ?>" style="width: 100%;">
        </p>
        <p>
            <label for="kk-agenda-end-time"><strong><?php esc_html_e('End time (optional)', 'koperasikita'); ?></strong></label><br>
            <input type="time" id="kk-agenda-end-time" name="kk_agenda_end_time" value="<?php echo esc_attr($endTime); ?>" style="width: 100%;">
        </p>
        <div class="kk-agenda-location-field">
            <label for="kk-agenda-location"><strong><?php esc_html_e('Location', 'koperasikita'); ?></strong></label>
            <div
                class="kk-agenda-location-search"
                data-kk-agenda-location-search
                data-kk-agenda-location-context="picker"
                data-kk-agenda-saved-locations="<?php echo esc_attr((string) wp_json_encode(self::savedLocations())); ?>"
                data-kk-agenda-searching="<?php esc_attr_e('Searching…', 'koperasikita'); ?>"
                data-kk-agenda-no-results="<?php esc_attr_e('No places found', 'koperasikita'); ?>"
                data-kk-agenda-search-unavailable="<?php esc_attr_e('Search is unavailable right now.', 'koperasikita'); ?>"
                data-kk-agenda-saved-label="<?php esc_attr_e('Saved location', 'koperasikita'); ?>"
                data-kk-agenda-place-label="<?php esc_attr_e('Places', 'koperasikita'); ?>"
            >
                <input
                    type="text"
                    id="kk-agenda-location"
                    name="kk_agenda_location"
                    value="<?php echo esc_attr($meta['location']); ?>"
                    placeholder="<?php esc_attr_e('Search for a place…', 'koperasikita'); ?>"
                    autocomplete="off"
                    role="combobox"
                    aria-expanded="false"
                    aria-controls="kk-agenda-location-results"
                    aria-autocomplete="list"
                >
                <ul class="kk-agenda-location-results" id="kk-agenda-location-results" role="listbox" hidden></ul>
            </div>
            <p class="description" data-kk-agenda-location-detail></p>
            <p class="kk-agenda-save-location" data-kk-agenda-save-location hidden>
                <label>
                    <input type="checkbox" name="kk_agenda_save_location" value="1" checked>
                    <?php esc_html_e('Save this location for reuse', 'koperasikita'); ?>
                </label>
            </p>
            <p class="description"><?php esc_html_e('Type to search saved locations and places.', 'koperasikita'); ?></p>
        </div>
        <div class="kk-agenda-map-picker" data-kk-agenda-map-picker data-kk-agenda-default-lat="<?php echo esc_attr((string) self::DEFAULT_LAT); ?>" data-kk-agenda-default-lng="<?php echo esc_attr((string) self::DEFAULT_LNG); ?>" data-kk-agenda-no-pin="<?php esc_attr_e('No pin placed', 'koperasikita'); ?>">
            <p>
                <button type="button" class="button" data-kk-agenda-pick><?php esc_html_e('Pick Location', 'koperasikita'); ?></button>
            </p>
            <p class="kk-agenda-coords" data-kk-agenda-coords><?php echo esc_html($coords); ?></p>
            <input type="hidden" name="kk_agenda_lat" value="<?php echo esc_attr($meta['lat'] !== null ? (string) $meta['lat'] : ''); ?>" data-kk-agenda-lat>
            <input type="hidden" name="kk_agenda_lng" value="<?php echo esc_attr($meta['lng'] !== null ? (string) $meta['lng'] : ''); ?>" data-kk-agenda-lng>
            <input type="hidden" name="kk_agenda_location_desc" value="<?php echo esc_attr($meta['location_desc']); ?>" data-kk-agenda-location-desc>
            <input type="hidden" name="kk_agenda_location_address" value="" data-kk-agenda-location-address>
        </div>
        <p class="description"><?php esc_html_e('Date, start time and location are required for the event to show up properly on the agenda archive.', 'koperasikita'); ?></p>
        <?php
    }

    /**
     * The shared Pick Location popup, rendered once at the end of the
     * agenda editor screen (post.php / post-new.php only) and the
     * Locations admin page. The submit form renders the same template
     * part itself, so all contexts share one popup and one script.
     */
    public static function renderPickLocationPopup(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen instanceof \WP_Screen) {
            return;
        }

        $isAgendaEditor = $screen->post_type === self::POST_TYPE && in_array($screen->base, ['post', 'post-new'], true);
        $isLocationsPage = $screen->id === \KoperasiKita\Admin\AdminMenu::submenuHookSuffix(self::LOCATIONS_PAGE_SLUG);

        if (!$isAgendaEditor && !$isLocationsPage) {
            return;
        }

        get_template_part('template-parts/agenda/popup-pick-location');
    }

    public static function saveMetaBox(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['kk_agenda_meta_nonce']) || !wp_verify_nonce(sanitize_key($_POST['kk_agenda_meta_nonce']), 'kk_agenda_meta_box')) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $date = isset($_POST['kk_agenda_date']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_date'])) : '';
        $startTime = isset($_POST['kk_agenda_start_time']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_start_time'])) : '';
        $endTime = isset($_POST['kk_agenda_end_time']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_end_time'])) : '';
        $location = isset($_POST['kk_agenda_location']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_location'])) : '';
        $lat = isset($_POST['kk_agenda_lat']) ? self::sanitizeLatitude(wp_unslash($_POST['kk_agenda_lat'])) : null;
        $lng = isset($_POST['kk_agenda_lng']) ? self::sanitizeLongitude(wp_unslash($_POST['kk_agenda_lng'])) : null;
        $locationDesc = isset($_POST['kk_agenda_location_desc']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_location_desc'])) : '';
        $locationAddress = isset($_POST['kk_agenda_location_address']) ? sanitize_text_field(wp_unslash($_POST['kk_agenda_location_address'])) : '';
        $saveLocation = isset($_POST['kk_agenda_save_location']) && $_POST['kk_agenda_save_location'] === '1';

        // Only overwrite the stored details when the editor actually sent
        // the box: a quick edit or a revision save must not wipe them.
        if ($date === '' && $startTime === '' && $endTime === '' && $location === '' && $lat === null && $lng === null && $locationDesc === '') {
            return;
        }

        $start = ($date !== '' && $startTime !== '') ? $date . ' ' . $startTime : '';
        $end = ($date !== '' && $endTime !== '') ? $date . ' ' . $endTime : '';

        self::saveMeta($postId, $start, $end, $location, $lat, $lng, $locationDesc);

        // The editor picked a new (geocoded) place and asked to keep
        // it: store it in the shared saved locations so the next event
        // finds it without searching again. The shared list is managed
        // by administrators, so a contributor's checkbox is ignored -
        // the event itself still keeps its coordinates.
        if ($saveLocation && $location !== '' && $lat !== null && $lng !== null && current_user_can('manage_options')) {
            self::addSavedLocation($location, $locationAddress, $lat, $lng, $locationDesc);
        }
    }

    /** @param array<string, string> $columns */
    public static function adminColumns(array $columns): array
    {
        $columns['kk_agenda_date'] = __('Date', 'koperasikita');
        $columns['kk_agenda_location'] = __('Location', 'koperasikita');

        return $columns;
    }

    public static function renderAdminColumn(string $column, int $postId): void
    {
        if ($column === 'kk_agenda_date') {
            $meta = self::getMeta($postId);
            echo $meta['start'] !== '' ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $meta['start_ts'])) : '&mdash;';
        }

        if ($column === 'kk_agenda_location') {
            $meta = self::getMeta($postId);
            echo $meta['location'] !== '' ? esc_html($meta['location']) : '&mdash;';
        }
    }

    // Contributor submission

    /**
     * Shortcode `[kk_agenda_submit]`: the contributor submission form.
     *
     * Visitors who are not logged in get a sign-in prompt, logged-in users
     * without the contributor capability get a plain explanation, and only
     * users who can submit see the form. The form posts back to the same
     * page and is processed on `init` (see handleSubmission), so a
     * successful submit redirects before any output - no double submit on
     * refresh.
     */
    public static function renderShortcode(): string
    {
        if (!is_user_logged_in()) {
            ob_start();
            ?>
            <div class="kk-agenda-submit">
                <p class="kk-agenda-notice">
                    <?php esc_html_e('Please sign in to submit an agenda item.', 'koperasikita'); ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php esc_html_e('Sign in', 'koperasikita'); ?></a>
                </p>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        if (!current_user_can('edit_posts')) {
            ob_start();
            ?>
            <div class="kk-agenda-submit">
                <p class="kk-agenda-notice"><?php esc_html_e('Your account cannot submit agenda items. Please contact an editor if you need contributor access.', 'koperasikita'); ?></p>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        $userId = get_current_user_id();
        $state = get_transient('kk_agenda_form_' . $userId);
        $state = is_array($state) ? $state : [];
        $errors = isset($state['errors']) && is_array($state['errors']) ? $state['errors'] : [];
        $values = isset($state['values']) && is_array($state['values']) ? $state['values'] : [];
        $submitted = isset($_GET['kk_agenda_submitted']) && $_GET['kk_agenda_submitted'] === '1';
        $failed = isset($_GET['kk_agenda_error']) && $_GET['kk_agenda_error'] === '1';

        // The error/success state is consumed on display: a refresh shows
        // a clean form instead of a stale message.
        if ($submitted || $failed || !empty($errors)) {
            delete_transient('kk_agenda_form_' . $userId);
        }

        ob_start();
        get_template_part(
            'template-parts/agenda/form-submit',
            null,
            [
                'errors' => $errors,
                'values' => $values,
                'submitted' => $submitted,
                'failed' => $failed,
            ]
        );

        return (string) ob_get_clean();
    }

    /**
     * Handles a submission POST on `init`, before any output, so the
     * post-redirect-get pattern works: success and validation errors both
     * redirect back to the form page instead of re-rendering on POST.
     *
     * The checks run in this order, and the first failure stops the chain:
     * logged in, nonce, capability, honeypot, rate limit, then field
     * validation. The post is always created as `pending` with the
     * logged-in user as author - the request carries no status and no
     * author, so neither can be forged.
     */
    public static function handleSubmission(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['kk_agenda_submit'])) {
            return;
        }

        $redirect = wp_get_referer() ?: home_url('/');

        if (!is_user_logged_in()) {
            wp_safe_redirect(add_query_arg('kk_agenda_error', 'login', $redirect));
            exit;
        }

        $userId = get_current_user_id();

        $fail = static function (string $code) use ($redirect, $userId): void {
            set_transient(
                'kk_agenda_form_' . $userId,
                ['errors' => ['form' => $code], 'values' => []],
                5 * MINUTE_IN_SECONDS
            );
            wp_safe_redirect(add_query_arg('kk_agenda_error', '1', $redirect));
            exit;
        };

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), self::NONCE_ACTION)) {
            $fail('security');
        }

        if (!current_user_can('edit_posts')) {
            $fail('capability');
        }

        // Honeypot: a filled "website" field means a bot. Pretend success
        // so the bot learns nothing, and store nothing.
        $honeypot = isset($_POST['kk_agenda_website']) ? trim(sanitize_text_field(wp_unslash($_POST['kk_agenda_website']))) : '';
        if ($honeypot !== '') {
            wp_safe_redirect(add_query_arg('kk_agenda_submitted', '1', $redirect));
            exit;
        }

        if (self::isRateLimited()) {
            $fail('rate');
        }

        $result = self::validateSubmission($_POST);

        if (!empty($result['errors'])) {
            self::bumpRateLimit();
            set_transient(
                'kk_agenda_form_' . $userId,
                ['errors' => $result['errors'], 'values' => $result['values']],
                5 * MINUTE_IN_SECONDS
            );
            wp_safe_redirect(add_query_arg('kk_agenda_error', '1', $redirect));
            exit;
        }

        $values = $result['values'];

        $postId = wp_insert_post(
            [
                'post_type' => self::POST_TYPE,
                'post_title' => $values['title'],
                'post_content' => $values['description'],
                'post_excerpt' => '',
                'post_status' => 'pending',
                'post_author' => $userId,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ],
            true
        );

        if (is_wp_error($postId) || $postId <= 0) {
            $fail('save');
        }

        self::saveMeta(
            (int) $postId,
            $values['start'],
            $values['end'],
            $values['location'],
            $values['lat'],
            $values['lng'],
            $values['location_desc']
        );

        self::bumpRateLimit();

        wp_safe_redirect(add_query_arg('kk_agenda_submitted', '1', $redirect));
        exit;
    }

    /**
     * Validates and sanitises a raw submission.
     *
     * Coordinates are optional enrichment: an invalid pin is dropped
     * silently instead of failing the whole submission - the venue text
     * is the required field, the pin only places it on a map.
     *
     * @param array<string, mixed> $input Raw $_POST data.
     * @return array{errors: array<string, string>, values: array<string, mixed>} Error codes keyed by field plus the cleaned values to re-fill the form with.
     */
    public static function validateSubmission(array $input): array
    {
        // Every field is read as a plain string: a crafted array value in
        // the request becomes an empty string instead of reaching the
        // sanitizers (or the database) as an unexpected type.
        $raw = static function (string $key) use ($input): string {
            $value = $input[$key] ?? '';
            return is_string($value) ? $value : '';
        };

        $title = sanitize_text_field(wp_unslash($raw('kk_agenda_title')));
        $date = sanitize_text_field(wp_unslash($raw('kk_agenda_date')));
        $startTime = sanitize_text_field(wp_unslash($raw('kk_agenda_start_time')));
        $endTime = sanitize_text_field(wp_unslash($raw('kk_agenda_end_time')));
        $location = sanitize_text_field(wp_unslash($raw('kk_agenda_location')));
        $description = wp_kses_post(wp_unslash($raw('kk_agenda_description')));
        $lat = self::sanitizeLatitude($raw('kk_agenda_lat'));
        $lng = self::sanitizeLongitude($raw('kk_agenda_lng'));
        $locationDesc = mb_substr(sanitize_text_field(wp_unslash($raw('kk_agenda_location_desc'))), 0, self::LOCATION_DESC_MAX);

        $errors = [];

        if (mb_strlen($title) < 3) {
            $errors['title'] = 'title_short';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = 'title_long';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['date'] = 'date_invalid';
        } else {
            $parts = explode('-', $date);
            if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                $errors['date'] = 'date_invalid';
            }
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            $errors['start_time'] = 'time_invalid';
        }

        if ($endTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
            $errors['end_time'] = 'time_invalid';
        }

        if (empty($errors['date']) && empty($errors['start_time'])) {
            $start = date_create_immutable_from_format('Y-m-d H:i', $date . ' ' . $startTime, wp_timezone());

            if ($start === false || $start->format('Y-m-d H:i') !== $date . ' ' . $startTime) {
                $errors['date'] = 'date_invalid';
            } elseif ((int) $start->getTimestamp() < current_time('timestamp') - 300) {
                // Five minutes of grace for clock skew; anything earlier
                // is an event in the past, not an agenda item.
                $errors['date'] = 'date_past';
            } elseif ($endTime !== '' && empty($errors['end_time'])) {
                $end = date_create_immutable_from_format('Y-m-d H:i', $date . ' ' . $endTime, wp_timezone());
                if ($end !== false && (int) $end->getTimestamp() <= (int) $start->getTimestamp()) {
                    $errors['end_time'] = 'end_before_start';
                }
            }
        }

        if (mb_strlen($location) < 3) {
            $errors['location'] = 'location_short';
        } elseif (mb_strlen($location) > 200) {
            $errors['location'] = 'location_long';
        }

        $plainDescription = trim(wp_strip_all_tags($description));
        if ($plainDescription === '') {
            $errors['description'] = 'description_empty';
        } elseif (mb_strlen($plainDescription) > 5000) {
            $errors['description'] = 'description_long';
        }

        return [
            'errors' => $errors,
            'values' => [
                'title' => $title,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'location' => $location,
                'description' => $description,
                'lat' => $lat,
                'lng' => $lng,
                'location_desc' => $locationDesc,
                'start' => ($date !== '' && $startTime !== '' && empty($errors['date']) && empty($errors['start_time'])) ? $date . ' ' . $startTime : '',
                'end' => ($date !== '' && $endTime !== '' && empty($errors['end_time'])) ? $date . ' ' . $endTime : '',
            ],
        ];
    }

    /**
     * The rate-limit key is a hash of the visitor IP, so the raw address
     * is never stored in the database.
     */
    private static function rateLimitKey(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';

        return 'kk_agenda_rate_' . md5($ip);
    }

    private static function isRateLimited(): bool
    {
        $count = (int) get_transient(self::rateLimitKey());

        return $count >= self::RATE_LIMIT_MAX;
    }

    private static function bumpRateLimit(): void
    {
        $key = self::rateLimitKey();
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
    }
}

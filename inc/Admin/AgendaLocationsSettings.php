<?php

declare(strict_types=1);

namespace KoperasiKita\Admin;

use KoperasiKita\Features\Agenda;

/**
 * The Agenda -> Locations admin screen.
 *
 * Owns the option registration, the sanitizer and the page markup for
 * the `kk_agenda_locations` option: places the editors use often, so
 * the Pick Location popup can offer them instead of a fresh search
 * every time. The data layer (Agenda::savedLocations) only reads the
 * option; nothing about the admin screen lives there.
 */
final class AgendaLocationsSettings
{
    public static function register(): void
    {
        // The page itself lives under the single "Koperasi Kita"
        // top-level menu (AdminMenu); only the settings
        // registration stays here.
        add_action('admin_init', [self::class, 'registerSettings']);
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
     * Sanitizer for the whole locations option. A row without a name or
     * with invalid coordinates is dropped, so a half-filled row can
     * never reach the picker; the description is truncated to
     * Agenda::LOCATION_DESC_MAX instead of dropping the row.
     *
     * @param mixed $input Raw submitted value.
     * @return array<int, array{name: string, address: string, lat: float, lng: float, description: string}>
     */
    public static function sanitizeLocations($input): array
    {
        $clean = [];

        if (!is_array($input)) {
            return $clean;
        }

        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = sanitize_text_field((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $lat = Agenda::sanitizeLatitude($row['lat'] ?? null);
            $lng = Agenda::sanitizeLongitude($row['lng'] ?? null);

            if ($lat === null || $lng === null) {
                continue;
            }

            $clean[] = [
                'name' => $name,
                'address' => sanitize_text_field((string) ($row['address'] ?? '')),
                'lat' => $lat,
                'lng' => $lng,
                'description' => mb_substr(sanitize_text_field((string) ($row['description'] ?? '')), 0, Agenda::LOCATION_DESC_MAX),
            ];
        }

        return $clean;
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'koperasikita'));
        }

        $locations = Agenda::savedLocations();
        ?>
        <div class="wrap" data-kk-agenda-locations-page>
            <h1><?php esc_html_e('Locations', 'koperasikita'); ?></h1>
            <p><?php esc_html_e('Save the places you use often. They appear in the Pick Location popup, so you can reuse a place instead of searching for it again.', 'koperasikita'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(Agenda::LOCATIONS_OPTION); ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Name', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Address', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Latitude', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Longitude', 'koperasikita'); ?></th>
                            <th scope="col"><?php esc_html_e('Location description', 'koperasikita'); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Remove', 'koperasikita'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody data-kk-agenda-locations-rows>
                        <?php foreach ($locations as $index => $location) : ?>
                            <?php self::renderLocationRow($index, $location); ?>
                        <?php endforeach; ?>
                        <?php if ($locations === []) : ?>
                            <tr data-kk-agenda-locations-empty>
                                <td colspan="6"><?php esc_html_e('No saved locations yet. Add one below.', 'koperasikita'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button" data-kk-agenda-location-add><?php esc_html_e('Add location', 'koperasikita'); ?></button>
                </p>
                <?php submit_button(); ?>
            </form>
            <template data-kk-agenda-location-template>
                <?php self::renderLocationRow('__INDEX__', ['name' => '', 'address' => '', 'lat' => '', 'lng' => '', 'description' => '']); ?>
            </template>
        </div>
        <?php
    }

    /**
     * One locations table row. The index is the array key in the
     * submitted option; the template row uses the __INDEX__ placeholder
     * the script replaces with the next free index.
     *
     * @param int|string $index Row index.
     * @param array{name: string, address: string, lat: float|string, lng: float|string, description: string} $location Row values.
     */
    private static function renderLocationRow($index, array $location): void
    {
        $name = Agenda::LOCATIONS_OPTION . '[' . $index . ']';
        ?>
        <tr data-kk-agenda-location-row>
            <td>
                <input type="text" name="<?php echo esc_attr($name); ?>[name]" value="<?php echo esc_attr((string) $location['name']); ?>" class="widefat" required>
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($name); ?>[address]" value="<?php echo esc_attr((string) $location['address']); ?>" class="widefat">
            </td>
            <td>
                <input type="number" name="<?php echo esc_attr($name); ?>[lat]" value="<?php echo esc_attr((string) $location['lat']); ?>" step="any" min="-90" max="90" inputmode="decimal" class="widefat" required>
            </td>
            <td>
                <input type="number" name="<?php echo esc_attr($name); ?>[lng]" value="<?php echo esc_attr((string) $location['lng']); ?>" step="any" min="-180" max="180" inputmode="decimal" class="widefat" required>
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($name); ?>[description]" value="<?php echo esc_attr((string) $location['description']); ?>" maxlength="<?php echo (int) Agenda::LOCATION_DESC_MAX; ?>" class="widefat">
            </td>
            <td>
                <button type="button" class="button" data-kk-agenda-location-remove><?php esc_html_e('Remove', 'koperasikita'); ?></button>
            </td>
        </tr>
        <?php
    }
}

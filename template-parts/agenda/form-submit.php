<?php
/**
 * Contributor agenda submission form.
 *
 * Rendered by the [kk_agenda_submit] shortcode with $args:
 *   errors    array<string, string>  Validation error codes keyed by field.
 *   values    array<string, string>  Previously submitted values, to re-fill the form.
 *   submitted bool                   A submission was just accepted for review.
 *   failed    bool                   The last submit failed validation.
 *
 * The form posts back to the same page and is handled on `init` before any
 * output (post-redirect-get), so a refresh never re-submits. The honeypot
 * field `kk_agenda_website` must stay empty: it is hidden from sighted
 * users and from assistive technology, and a filled value silently
 * discards the submission as bot traffic.
 *
 * @package KoperasiKita
 */

$errors = isset($args['errors']) && is_array($args['errors']) ? $args['errors'] : [];
$values = isset($args['values']) && is_array($args['values']) ? $args['values'] : [];
$submitted = !empty($args['submitted']);
$failed = !empty($args['failed']);

$value = static function (string $key) use ($values): string {
    return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : '';
};

$fieldErrors = [
    'title' => [
        'title_short' => __('Please give the event a title of at least 3 characters.', 'koperasikita'),
        'title_long' => __('The title is too long. Please keep it under 200 characters.', 'koperasikita'),
    ],
    'date' => [
        'date_invalid' => __('Please pick a valid date.', 'koperasikita'),
        'date_past' => __('The event date is in the past. Please pick a future date.', 'koperasikita'),
    ],
    'start_time' => [
        'time_invalid' => __('Please pick a valid start time.', 'koperasikita'),
    ],
    'end_time' => [
        'time_invalid' => __('Please pick a valid end time.', 'koperasikita'),
        'end_before_start' => __('The end time must be after the start time.', 'koperasikita'),
    ],
    'location' => [
        'location_short' => __('Please tell us where the event takes place (at least 3 characters).', 'koperasikita'),
        'location_long' => __('The location is too long. Please keep it under 200 characters.', 'koperasikita'),
    ],
    'description' => [
        'description_empty' => __('Please write a short description of the event.', 'koperasikita'),
        'description_long' => __('The description is too long. Please keep it under 5000 characters.', 'koperasikita'),
    ],
];

$formErrors = [
    'security' => __('Your session expired. Please try submitting again.', 'koperasikita'),
    'capability' => __('Your account cannot submit agenda items.', 'koperasikita'),
    'rate' => __('Too many submissions. Please wait an hour and try again.', 'koperasikita'),
    'save' => __('Something went wrong while saving your submission. Please try again.', 'koperasikita'),
];

$fieldMessage = static function (string $field) use ($errors, $fieldErrors): string {
    $code = $errors[$field] ?? '';
    return $code !== '' && isset($fieldErrors[$field][$code]) ? $fieldErrors[$field][$code] : '';
};

$formMessage = '';
if (isset($errors['form']) && isset($formErrors[$errors['form']])) {
    $formMessage = $formErrors[$errors['form']];
}
?>

<div class="kk-agenda-submit">
    <?php if ($submitted) : ?>
        <p class="kk-agenda-notice kk-agenda-notice-success" role="status">
            <?php esc_html_e('Thank you! Your agenda item was submitted for review. An editor will publish it once it has been checked.', 'koperasikita'); ?>
        </p>
    <?php endif; ?>

    <?php if ($failed && $formMessage !== '') : ?>
        <p class="kk-agenda-notice kk-agenda-notice-error" role="alert"><?php echo esc_html($formMessage); ?></p>
    <?php elseif ($failed) : ?>
        <p class="kk-agenda-notice kk-agenda-notice-error" role="alert"><?php esc_html_e('Please fix the highlighted fields and submit again.', 'koperasikita'); ?></p>
    <?php endif; ?>

    <form
        class="kk-agenda-form"
        method="post"
        action="<?php echo esc_url(get_permalink()); ?>"
        data-kk-agenda-form
        novalidate
    >
        <?php wp_nonce_field(\KoperasiKita\Features\Agenda::NONCE_ACTION); ?>
        <input type="hidden" name="kk_agenda_submit" value="1">

        <p class="kk-agenda-field">
            <label for="kk-agenda-title"><?php esc_html_e('Event title', 'koperasikita'); ?></label>
            <input
                type="text"
                id="kk-agenda-title"
                name="kk_agenda_title"
                value="<?php echo esc_attr($value('title')); ?>"
                maxlength="200"
                required
                autocomplete="off"
                <?php if ($fieldMessage('title') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-title-error"<?php endif; ?>
            >
            <?php if ($fieldMessage('title') !== '') : ?>
                <span class="kk-agenda-error" id="kk-agenda-title-error"><?php echo esc_html($fieldMessage('title')); ?></span>
            <?php endif; ?>
        </p>

        <div class="kk-agenda-field-row">
            <p class="kk-agenda-field">
                <label for="kk-agenda-date"><?php esc_html_e('Date', 'koperasikita'); ?></label>
                <input
                    type="date"
                    id="kk-agenda-date"
                    name="kk_agenda_date"
                    value="<?php echo esc_attr($value('date')); ?>"
                    required
                    <?php if ($fieldMessage('date') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-date-error"<?php endif; ?>
                >
                <?php if ($fieldMessage('date') !== '') : ?>
                    <span class="kk-agenda-error" id="kk-agenda-date-error"><?php echo esc_html($fieldMessage('date')); ?></span>
                <?php endif; ?>
            </p>

            <p class="kk-agenda-field">
                <label for="kk-agenda-start-time"><?php esc_html_e('Start time', 'koperasikita'); ?></label>
                <input
                    type="time"
                    id="kk-agenda-start-time"
                    name="kk_agenda_start_time"
                    value="<?php echo esc_attr($value('start_time')); ?>"
                    required
                    <?php if ($fieldMessage('start_time') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-start-time-error"<?php endif; ?>
                >
                <?php if ($fieldMessage('start_time') !== '') : ?>
                    <span class="kk-agenda-error" id="kk-agenda-start-time-error"><?php echo esc_html($fieldMessage('start_time')); ?></span>
                <?php endif; ?>
            </p>

            <p class="kk-agenda-field">
                <label for="kk-agenda-end-time"><?php esc_html_e('End time (optional)', 'koperasikita'); ?></label>
                <input
                    type="time"
                    id="kk-agenda-end-time"
                    name="kk_agenda_end_time"
                    value="<?php echo esc_attr($value('end_time')); ?>"
                    <?php if ($fieldMessage('end_time') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-end-time-error"<?php endif; ?>
                >
                <?php if ($fieldMessage('end_time') !== '') : ?>
                    <span class="kk-agenda-error" id="kk-agenda-end-time-error"><?php echo esc_html($fieldMessage('end_time')); ?></span>
                <?php endif; ?>
            </p>
        </div>

        <div class="kk-agenda-field">
            <label for="kk-agenda-location"><?php esc_html_e('Location', 'koperasikita'); ?></label>
            <div
                class="kk-agenda-location-search"
                data-kk-agenda-location-search
                data-kk-agenda-location-context="picker"
                data-kk-agenda-saved-locations="<?php echo esc_attr((string) wp_json_encode(\KoperasiKita\Features\Agenda::savedLocations())); ?>"
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
                    value="<?php echo esc_attr($value('location')); ?>"
                    maxlength="200"
                    placeholder="<?php esc_attr_e('Search for a place…', 'koperasikita'); ?>"
                    required
                    autocomplete="off"
                    role="combobox"
                    aria-expanded="false"
                    aria-controls="kk-agenda-location-results"
                    aria-autocomplete="list"
                    <?php if ($fieldMessage('location') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-location-error"<?php endif; ?>
                >
                <ul class="kk-agenda-location-results" id="kk-agenda-location-results" role="listbox" hidden></ul>
            </div>
            <?php if ($fieldMessage('location') !== '') : ?>
                <span class="kk-agenda-error" id="kk-agenda-location-error"><?php echo esc_html($fieldMessage('location')); ?></span>
            <?php endif; ?>
        </div>

        <?php
        $formLat = isset($values['lat']) && is_float($values['lat']) ? $values['lat'] : null;
        $formLng = isset($values['lng']) && is_float($values['lng']) ? $values['lng'] : null;
        $formDesc = isset($values['location_desc']) && is_string($values['location_desc']) ? $values['location_desc'] : '';
        $formCoords = ($formLat !== null && $formLng !== null)
            ? $formLat . ', ' . $formLng
            : __('No pin placed', 'koperasikita');
        ?>
        <div class="kk-agenda-map-picker" data-kk-agenda-map-picker data-kk-agenda-default-lat="<?php echo esc_attr((string) \KoperasiKita\Features\Agenda::DEFAULT_LAT); ?>" data-kk-agenda-default-lng="<?php echo esc_attr((string) \KoperasiKita\Features\Agenda::DEFAULT_LNG); ?>" data-kk-agenda-no-pin="<?php esc_attr_e('No pin placed', 'koperasikita'); ?>">
            <p class="kk-agenda-field">
                <button type="button" class="kk-button" data-kk-agenda-pick><?php esc_html_e('Pick Location', 'koperasikita'); ?></button>
            </p>
            <p class="kk-agenda-coords" data-kk-agenda-coords><?php echo esc_html($formCoords); ?></p>
            <input type="hidden" name="kk_agenda_lat" value="<?php echo esc_attr($formLat !== null ? (string) $formLat : ''); ?>" data-kk-agenda-lat>
            <input type="hidden" name="kk_agenda_lng" value="<?php echo esc_attr($formLng !== null ? (string) $formLng : ''); ?>" data-kk-agenda-lng>
            <input type="hidden" name="kk_agenda_location_desc" value="<?php echo esc_attr($formDesc); ?>" data-kk-agenda-location-desc>
        </div>

        <p class="kk-agenda-field">
            <label for="kk-agenda-description"><?php esc_html_e('Description', 'koperasikita'); ?></label>
            <textarea
                id="kk-agenda-description"
                name="kk_agenda_description"
                rows="5"
                maxlength="5000"
                required
                <?php if ($fieldMessage('description') !== '') : ?>aria-invalid="true" aria-describedby="kk-agenda-description-error"<?php endif; ?>
            ><?php echo esc_textarea($value('description')); ?></textarea>
            <?php if ($fieldMessage('description') !== '') : ?>
                <span class="kk-agenda-error" id="kk-agenda-description-error"><?php echo esc_html($fieldMessage('description')); ?></span>
            <?php endif; ?>
        </p>

        <p class="kk-agenda-honeypot" aria-hidden="true">
            <label for="kk-agenda-website"><?php esc_html_e('Website', 'koperasikita'); ?></label>
            <input type="text" id="kk-agenda-website" name="kk_agenda_website" value="" tabindex="-1" autocomplete="off">
        </p>

        <p class="kk-agenda-actions">
            <button type="submit" class="kk-button kk-agenda-submit-button" data-kk-agenda-submit>
                <?php esc_html_e('Submit for review', 'koperasikita'); ?>
            </button>
        </p>

        <p class="kk-agenda-hint"><?php esc_html_e('Your submission is saved as a draft for review. It only appears on the agenda once an editor publishes it.', 'koperasikita'); ?></p>
    </form>

    <?php get_template_part('template-parts/agenda/popup-pick-location'); ?>
</div>

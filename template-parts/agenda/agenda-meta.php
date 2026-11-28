<?php
/**
 * Agenda event details: date, time and location for one agenda item.
 *
 * Expects the global $post to be an agenda item. Items without stored
 * details render nothing instead of an empty block.
 *
 * @package KoperasiKita
 */

$meta = \KoperasiKita\Features\Agenda::getMeta(get_the_ID());

if ($meta['start'] === '' && $meta['location'] === '') {
    return;
}

$dateFormat = get_option('date_format');
$startTs = \KoperasiKita\Features\Agenda::eventTimestamp($meta['start']);
$endTs = \KoperasiKita\Features\Agenda::eventTimestamp($meta['end']);
// wp_date, not date_i18n: date_i18n expects a timestamp summed with
// the timezone offset (legacy quirk), so a true Unix timestamp renders
// shifted by the offset. wp_date takes a true timestamp.
$dateLabel = $startTs > 0 ? wp_date($dateFormat, $startTs) : '';
$endDateLabel = $endTs > 0 ? wp_date($dateFormat, $endTs) : '';
// A multi-day event ends on a different date than it starts: show
// the date range instead of the start date alone. A same-day event
// keeps the single date, exactly as before.
$startDate = $meta['start'] !== '' ? substr($meta['start'], 0, 10) : '';
$endDate = $meta['end'] !== '' ? substr($meta['end'], 0, 10) : '';
$isMultiDay = $dateLabel !== '' && $endDateLabel !== '' && $endDate !== '' && $endDate !== $startDate;
$startLabel = $startTs > 0 ? wp_date('H.i', $startTs) : '';
$endLabel = $endTs > 0 ? wp_date('H.i', $endTs) : '';
// Event times are WIB wall-clock times: 24-hour with a dot, and the
// zone abbreviation, so the header reads the same as the body text.
$timeLabel = $startLabel;
if ($startLabel !== '' && $endLabel !== '') {
    $timeLabel .= ' &ndash; ' . $endLabel;
}
if ($timeLabel !== '') {
    $timeLabel .= ' WIB';
}
?>

<dl class="kk-agenda-meta">
    <?php if ($dateLabel !== '') : ?>
        <div class="kk-agenda-meta-row">
            <dt><?php esc_html_e('Date', 'koperasikita'); ?></dt>
            <dd><time datetime="<?php echo esc_attr($startDate); ?>"><?php echo esc_html($dateLabel); ?></time><?php if ($isMultiDay) : ?> &ndash; <time datetime="<?php echo esc_attr($endDate); ?>"><?php echo esc_html($endDateLabel); ?></time><?php endif; ?></dd>
        </div>
    <?php endif; ?>

    <?php if ($timeLabel !== '') : ?>
        <div class="kk-agenda-meta-row">
            <dt><?php esc_html_e('Time', 'koperasikita'); ?></dt>
            <dd><?php echo wp_kses_post($timeLabel); ?></dd>
        </div>
    <?php endif; ?>
</dl>

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
$timeFormat = get_option('time_format');
$dateLabel = $meta['start_ts'] > 0 ? date_i18n($dateFormat, $meta['start_ts']) : '';
$startLabel = $meta['start_ts'] > 0 ? date_i18n($timeFormat, $meta['start_ts']) : '';
$endLabel = '';
if ($meta['end'] !== '') {
    $endParsed = date_create_immutable_from_format('Y-m-d H:i', $meta['end'], wp_timezone());
    $endLabel = $endParsed !== false ? date_i18n($timeFormat, (int) $endParsed->getTimestamp()) : '';
}
$timeLabel = $startLabel !== '' && $endLabel !== '' ? $startLabel . ' &ndash; ' . $endLabel : $startLabel;
?>

<dl class="kk-agenda-meta">
    <?php if ($dateLabel !== '') : ?>
        <div class="kk-agenda-meta-row">
            <dt><?php esc_html_e('Date', 'koperasikita'); ?></dt>
            <dd><time datetime="<?php echo esc_attr(substr($meta['start'], 0, 10)); ?>"><?php echo esc_html($dateLabel); ?></time></dd>
        </div>
    <?php endif; ?>

    <?php if ($timeLabel !== '') : ?>
        <div class="kk-agenda-meta-row">
            <dt><?php esc_html_e('Time', 'koperasikita'); ?></dt>
            <dd><?php echo wp_kses_post($timeLabel); ?></dd>
        </div>
    <?php endif; ?>
</dl>

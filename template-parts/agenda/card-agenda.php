<?php
/**
 * Agenda card: date badge, title, time/location, excerpt.
 * Used on the agenda archive. Expects the global $post to be an agenda item.
 *
 * @package KoperasiKita
 */

$meta = \KoperasiKita\Features\Agenda::getMeta(get_the_ID());
$thumb_url = \KoperasiKita\Images\FeaturedImage::url(get_the_ID(), 'medium');
$day = $meta['start_ts'] > 0 ? date_i18n('j', $meta['start_ts']) : '';
$month = $meta['start_ts'] > 0 ? date_i18n('M', $meta['start_ts']) : '';
?>

<article class="kk-card kk-agenda-card">
    <?php if ($thumb_url) : ?>
        <a class="kk-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
            <img src="<?php echo esc_url($thumb_url); ?>" alt=""<?php if (\KoperasiKita\Images\FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
        </a>
    <?php endif; ?>

    <div class="kk-card-body">
        <?php if ($day !== '' && $month !== '') : ?>
            <p class="kk-agenda-date-badge" aria-hidden="true">
                <span class="kk-agenda-date-day"><?php echo esc_html($day); ?></span>
                <span class="kk-agenda-date-month"><?php echo esc_html($month); ?></span>
            </p>
        <?php endif; ?>

        <h3 class="kk-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

        <?php get_template_part('template-parts/agenda/agenda-meta'); ?>

        <p class="kk-card-excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>
    </div>
</article>

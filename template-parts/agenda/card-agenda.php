<?php
/**
 * Agenda card: date badge, title, time/location, excerpt.
 * Used on the agenda archive (and the /posts endpoint for later
 * pages). Expects the global $post to be an agenda item.
 *
 * The date badge is a direct child of the article, not part of the
 * body: in grid view it overlays the thumbnail, top-left. In list
 * view that same badge is hidden and a second badge inside the
 * body, on the RIGHT beside the Date/Time rows (the single-page
 * pattern, badge after the rows), is shown instead - the list
 * badge never gets its own side column next to the thumbnail.
 *
 * @package KoperasiKita
 */

$meta = \KoperasiKita\Features\Agenda::getMeta(get_the_ID());
$thumb_url = \KoperasiKita\Images\FeaturedImage::url(get_the_ID(), 'medium');
$eventTs = \KoperasiKita\Features\Agenda::eventTimestamp($meta['start']);
$day = $eventTs > 0 ? wp_date('j', $eventTs) : '';
$month = $eventTs > 0 ? wp_date('M', $eventTs) : '';
?>

<article class="kk-card kk-agenda-card">
    <?php if ($day !== '' && $month !== '') : ?>
        <p class="kk-agenda-date-badge" aria-hidden="true">
            <span class="kk-agenda-date-day"><?php echo esc_html($day); ?></span>
            <span class="kk-agenda-date-month"><?php echo esc_html($month); ?></span>
        </p>
    <?php endif; ?>

    <?php if ($thumb_url) : ?>
        <a class="kk-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
            <img src="<?php echo esc_url($thumb_url); ?>" alt=""<?php if (\KoperasiKita\Images\FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
        </a>
    <?php endif; ?>

    <div class="kk-card-body">
        <h3 class="kk-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

        <div class="kk-agenda-details">
            <?php get_template_part('template-parts/agenda/agenda-meta'); ?>

            <?php if ($day !== '' && $month !== '') : ?>
                <p class="kk-agenda-date-badge" aria-hidden="true">
                    <span class="kk-agenda-date-day"><?php echo esc_html($day); ?></span>
                    <span class="kk-agenda-date-month"><?php echo esc_html($month); ?></span>
                </p>
            <?php endif; ?>
        </div>

        <?php if (\KoperasiKita\Features\Agenda::showExcerpt()) : ?>
        <p class="kk-card-excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </div>
</article>

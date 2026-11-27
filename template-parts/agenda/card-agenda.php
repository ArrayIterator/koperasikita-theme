<?php
/**
 * Agenda card: date badge, title, time/location, excerpt.
 * Used on the agenda archive (and the /posts endpoint for later
 * pages). Expects the global $post to be an agenda item.
 *
 * The date badge is a direct child of the article, not part of the
 * body: the grid layout overlays it on the thumbnail and the list
 * layout puts it in a side column, both via grid areas.
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

        <?php get_template_part('template-parts/agenda/agenda-meta'); ?>

        <p class="kk-card-excerpt"><?php echo esc_html(get_the_excerpt()); ?></p>
    </div>
</article>

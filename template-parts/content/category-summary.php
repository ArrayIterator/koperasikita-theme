<?php
/**
 * Category Summary: one column per category, each with a coloured
 * top border (green, gold, green, ...), the category name, the
 * latest post title, its excerpt and its time. The section header
 * carries the title on the left and the index link on the right.
 *
 * @package KoperasiKita
 *
 * @var string $title Section title (may be empty).
 * @var string $index_label Index link label.
 * @var string $index_url Index link URL (empty = no link shown).
 * @var array<int, array{term: WP_Term, posts: WP_Post[]}> $columns
 * @var bool $show_excerpt
 * @var bool $show_time
 */

$title = $args['title'] ?? '';
$indexLabel = $args['index_label'] ?? '';
$indexUrl = $args['index_url'] ?? '';
$columns = $args['columns'] ?? [];
$showExcerpt = (bool) ($args['show_excerpt'] ?? true);
$showTime = (bool) ($args['show_time'] ?? true);
?>
<section class="kk-category-summary">
    <?php if ($title !== '' || $indexUrl !== '') : ?>
        <div class="kk-category-summary-header">
            <?php if ($title !== '') : ?>
                <h2 class="kk-category-summary-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            <?php if ($indexUrl !== '') : ?>
                <a class="kk-category-summary-index" href="<?php echo esc_url($indexUrl); ?>">
                    <?php echo esc_html($indexLabel !== '' ? $indexLabel : __('Indeks Berita', 'koperasikita')); ?>
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="kk-category-summary-grid">
        <?php foreach ($columns as $index => $column) : ?>
            <?php
            $term = $column['term'];
            $termLink = get_term_link($term);
            $termLink = is_wp_error($termLink) ? '' : (string) $termLink;
            $accent = $index % 2 === 0 ? 'green' : 'gold';
            ?>
            <div class="kk-category-summary-column kk-category-summary-column--<?php echo esc_attr($accent); ?>">
                <?php if ($termLink !== '') : ?>
                    <a class="kk-category-summary-term" href="<?php echo esc_url($termLink); ?>"><?php echo esc_html($term->name); ?></a>
                <?php else : ?>
                    <span class="kk-category-summary-term"><?php echo esc_html($term->name); ?></span>
                <?php endif; ?>
                <?php foreach ($column['posts'] as $post) : ?>
                    <?php
                    setup_postdata($post);
                    // Today's post shows its time (08.05 WIB); an
                    // older post shows its date instead - a bare time
                    // would not say which day it was.
                    $timestamp = get_post_timestamp($post);
                    $isToday = $timestamp !== false && date_i18n('Y-m-d', $timestamp) === date_i18n('Y-m-d', current_time('timestamp'));
                    $timeLabel = $isToday
                        ? date_i18n('H.i', $timestamp) . ' ' . date_i18n('T', $timestamp)
                        : get_the_date('', $post);
                    // The excerpt is generated from the post content
                    // when the author wrote no manual excerpt, so
                    // gating on has_excerpt() hid it for most posts.
                    // Follow the card templates: show the generated
                    // excerpt whenever one exists.
                    $excerpt = trim((string) get_the_excerpt($post));
                    ?>
                    <article class="kk-category-summary-item">
                        <h3 class="kk-category-summary-item-title">
                            <a href="<?php echo esc_url((string) get_permalink($post)); ?>"><?php echo esc_html((string) get_the_title($post)); ?></a>
                        </h3>
                        <?php if ($showExcerpt && $excerpt !== '') : ?>
                            <p class="kk-category-summary-item-excerpt"><?php echo esc_html($excerpt); ?></p>
                        <?php endif; ?>
                        <?php if ($showTime) : ?>
                            <time class="kk-category-summary-item-time" datetime="<?php echo esc_attr($timestamp !== false ? date_i18n('c', $timestamp) : ''); ?>"><?php echo esc_html($timeLabel); ?></time>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php wp_reset_postdata(); ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

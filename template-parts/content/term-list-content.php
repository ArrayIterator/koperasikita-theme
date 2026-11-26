<?php
/**
 * Term list content: posts from one category or tag, in one of five
 * layouts. Expects $args['posts'] (WP_Post[], newest first) and
 * $args['layout'] (featured | grid | list | featured-right |
 * list-column). The widget title (custom, or the term name, optionally
 * linked to the term archive) is rendered by the widget itself via
 * WidgetHeader — this template only renders the posts.
 *
 * - featured: the first post large (thumbnail, title, excerpt, date),
 *   the rest as a compact title + date list.
 * - grid: two-column cards (thumbnail, title, date).
 * - list: vertical rows (thumbnail, title, date).
 * - featured-right: the list on the left, the featured post on the
 *   right.
 * - list-column: the same rows as list, in two columns.
 *
 * @package KoperasiKita
 */

use KoperasiKita\Images\FeaturedImage;

$posts = isset($args['posts']) && is_array($args['posts']) ? $args['posts'] : [];
$layout = isset($args['layout']) && in_array($args['layout'], ['featured', 'grid', 'list', 'featured-right', 'list-column'], true)
	? (string) $args['layout']
	: 'featured';

if ($posts === []) {
	return;
}

$featured = $posts[0];
$rest = array_slice($posts, 1);
$thumb = static function (int $postId, string $size = 'medium'): string {
	return FeaturedImage::url($postId, $size);
};
?>

<?php if ($layout === 'featured' || $layout === 'featured-right') : ?>
	<div class="kk-term-list kk-term-list--<?php echo esc_attr($layout); ?>">
		<article class="kk-term-list-featured">
			<a class="kk-term-list-featured-link" href="<?php echo esc_url(get_permalink($featured)); ?>">
				<span class="kk-term-list-featured-thumb">
					<img src="<?php echo esc_url($thumb($featured->ID, 'large')); ?>" alt=""<?php if (FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
				</span>
				<span class="kk-term-list-featured-text">
					<span class="kk-term-list-featured-title"><?php echo esc_html(get_the_title($featured)); ?></span>
					<span class="kk-term-list-featured-excerpt"><?php echo esc_html(get_the_excerpt($featured)); ?></span>
					<span class="kk-term-list-date"><?php echo esc_html(get_the_date('', $featured)); ?></span>
				</span>
			</a>
		</article>
		<?php if ($rest !== []) : ?>
			<ul class="kk-term-list-rest">
				<?php foreach ($rest as $post) : ?>
					<li class="kk-term-list-row">
						<a class="kk-term-list-row-link" href="<?php echo esc_url(get_permalink($post)); ?>">
							<span class="kk-term-list-row-title"><?php echo esc_html(get_the_title($post)); ?></span>
							<span class="kk-term-list-date"><?php echo esc_html(get_the_date('', $post)); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
<?php elseif ($layout === 'grid') : ?>
	<div class="kk-term-list kk-term-list--grid">
		<?php foreach ($posts as $post) : ?>
			<article class="kk-term-list-card">
				<a class="kk-term-list-card-link" href="<?php echo esc_url(get_permalink($post)); ?>">
					<span class="kk-term-list-card-thumb">
						<img src="<?php echo esc_url($thumb($post->ID)); ?>" alt=""<?php if (FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
					</span>
					<span class="kk-term-list-card-title"><?php echo esc_html(get_the_title($post)); ?></span>
					<span class="kk-term-list-date"><?php echo esc_html(get_the_date('', $post)); ?></span>
				</a>
			</article>
		<?php endforeach; ?>
	</div>
<?php elseif ($layout === 'list-column') : ?>
	<ul class="kk-term-list kk-term-list--list-column">
		<?php foreach ($posts as $post) : ?>
			<li class="kk-term-list-row">
				<a class="kk-term-list-row-link" href="<?php echo esc_url(get_permalink($post)); ?>">
					<span class="kk-term-list-row-thumb">
						<img src="<?php echo esc_url($thumb($post->ID)); ?>" alt=""<?php if (FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
					</span>
					<span class="kk-term-list-row-text">
						<span class="kk-term-list-row-title"><?php echo esc_html(get_the_title($post)); ?></span>
						<span class="kk-term-list-date"><?php echo esc_html(get_the_date('', $post)); ?></span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<ul class="kk-term-list kk-term-list--list">
		<?php foreach ($posts as $post) : ?>
			<li class="kk-term-list-row">
				<a class="kk-term-list-row-link" href="<?php echo esc_url(get_permalink($post)); ?>">
					<span class="kk-term-list-row-thumb">
						<img src="<?php echo esc_url($thumb($post->ID)); ?>" alt=""<?php if (FeaturedImage::isLazyEnabled()) : ?> loading="lazy"<?php endif; ?>>
					</span>
					<span class="kk-term-list-row-text">
						<span class="kk-term-list-row-title"><?php echo esc_html(get_the_title($post)); ?></span>
						<span class="kk-term-list-date"><?php echo esc_html(get_the_date('', $post)); ?></span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

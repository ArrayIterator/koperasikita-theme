<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Agenda;
use WP_Query;
use WP_Widget;

/**
 * Agenda widget: upcoming cooperative events as a dark card, matching
 * the "AGENDA KOPERASI" design — a date badge (day + month) on the
 * left, the title and a pin + location on the right. Status stays
 * subtle: a live event gets a green dot on its date badge, a
 * finished event is dimmed, upcoming events are plain. Upcoming items
 * come first, soonest first; when "Show past events" is on, finished
 * items fill the remaining slots, most recent first. Items without a
 * date are never shown — the badge has nothing to print. The header
 * always renders: an empty title falls back to "Agenda Koperasi", so
 * the card (and its block-editor preview) never loses its header.
 */
final class AgendaWidget extends WP_Widget
{
    private const DEFAULT_COUNT = 5;
    private const MAX_COUNT = 10;

    public function __construct()
    {
        parent::__construct(
            'kk_agenda',
            __('Koperasi Kita Agenda', 'koperasikita'),
            [
                'description' => __('Upcoming cooperative events as a dark card; live events get a green dot, finished events are dimmed.', 'koperasikita'),
                'classname' => 'kk-agenda-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        if (!Agenda::isEnabled()) {
            return;
        }

        $title = isset($instance['title']) ? (string) $instance['title'] : '';

        if ($title === '') {
            $title = __('Agenda Koperasi', 'koperasikita');
        }

        $count = isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT;
        $showPast = isset($instance['show_past']) ? (bool) $instance['show_past'] : true;

        $items = self::items($count, $showPast);

        echo $args['before_widget'] ?? '';

        // The title lives inside the dark card (icon + "AGENDA KOPERASI"),
        // so this widget deliberately skips WidgetHeader::render(). The
        // template renders the card even with no items — the empty state
        // is an honest line inside the same card, never fake events.
        get_template_part('template-parts/agenda/widget', null, [
            'title' => $title,
            'items' => $items,
        ]);

        echo $args['after_widget'] ?? '';
    }

    /**
     * Upcoming items first (soonest first), then — when $showPast —
     * finished items to fill the remaining slots (most recent first).
     * Dateless items are excluded: the stored sort key for them is the
     * far-future NO_DATE_TS sentinel, which would otherwise read as an
     * event in the year 2100.
     *
     * @return array<int, array{title: string, url: string, day: string, month: string, location: string, status: string}>
     */
    private static function items(int $count, bool $showPast): array
    {
        $now = (int) current_time('timestamp');
        $items = [];

        $upcoming = new WP_Query([
            'post_type' => Agenda::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'meta_key' => Agenda::META_START_TS,
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => Agenda::META_START_TS,
                    'value' => $now,
                    'compare' => '>=',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => Agenda::META_START_TS,
                    'value' => Agenda::NO_DATE_TS,
                    'compare' => '<',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        foreach ($upcoming->posts as $post) {
            $items[] = self::item($post->ID, $now);
        }

        $remaining = $count - count($items);

        if ($showPast && $remaining > 0) {
            $past = new WP_Query([
                'post_type' => Agenda::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => $remaining,
                'meta_key' => Agenda::META_START_TS,
                'orderby' => 'meta_value_num',
                'order' => 'DESC',
                'no_found_rows' => true,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => Agenda::META_START_TS,
                        'value' => $now,
                        'compare' => '<',
                        'type' => 'NUMERIC',
                    ],
                    [
                        'key' => Agenda::META_START_TS,
                        'value' => 0,
                        'compare' => '>',
                        'type' => 'NUMERIC',
                    ],
                ],
            ]);

            foreach ($past->posts as $post) {
                $items[] = self::item($post->ID, $now);
            }
        }

        return array_values(array_filter($items));
    }

    /**
     * One item for the template. The status: "done" when the end time
     * (if any) has passed, "live" while the event is running,
     * "upcoming" before it starts. A dateless item returns null — the
     * caller filters it out.
     *
     * @return array{title: string, url: string, day: string, month: string, location: string, status: string}|null
     */
    private static function item(int $postId, int $now): ?array
    {
        $meta = Agenda::getMeta($postId);
        $startTs = (int) $meta['start_ts'];

        if ($startTs <= 0) {
            return null;
        }

        $endTs = 0;

        if ($meta['end'] !== '') {
            $parsed = date_create_immutable_from_format('Y-m-d H:i', $meta['end'], wp_timezone());
            $endTs = $parsed !== false ? (int) $parsed->getTimestamp() : 0;
        }

        if ($endTs > 0 && $endTs < $now) {
            $status = 'done';
        } elseif ($startTs <= $now && ($endTs === 0 || $now <= $endTs)) {
            $status = 'live';
        } else {
            $status = 'upcoming';
        }

        return [
            'title' => get_the_title($postId),
            'url' => (string) get_permalink($postId),
            'day' => date_i18n('j', $startTs),
            'month' => date_i18n('M', $startTs),
            'location' => (string) $meta['location'],
            'status' => $status,
        ];
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Agenda Koperasi', 'koperasikita');
        $count = isset($instance['count']) ? max(1, min(self::MAX_COUNT, (int) $instance['count'])) : self::DEFAULT_COUNT;
        $showPast = isset($instance['show_past']) ? (bool) $instance['show_past'] : true;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                value="<?php echo esc_attr($title); ?>"
            >
        </p>
        <?php // No header-style field: the title renders inside the dark card. ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('count')); ?>">
                <?php esc_html_e('Number of items', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="1"
                max="<?php echo esc_attr((string) self::MAX_COUNT); ?>"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('count')); ?>"
                name="<?php echo esc_attr($this->get_field_name('count')); ?>"
                value="<?php echo esc_attr((string) $count); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('show_past')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('show_past')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('show_past')); ?>"
                    value="1"
                    <?php checked($showPast); ?>
                >
                <?php esc_html_e('Show past events', 'koperasikita'); ?>
            </label>
            <span class="description">
                <?php esc_html_e('Fill the remaining slots with finished events, most recent first.', 'koperasikita'); ?>
            </span>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $new New settings.
     * @param array<string, mixed> $old Previous settings.
     * @return array<string, mixed>
     */
    public function update($new, $old): array
    {
        $instance = [];
        $instance['title'] = isset($new['title']) ? sanitize_text_field((string) $new['title']) : '';
        $instance['count'] = isset($new['count'])
            ? max(1, min(self::MAX_COUNT, (int) $new['count']))
            : self::DEFAULT_COUNT;
        $instance['show_past'] = !empty($new['show_past']);

        return $instance;
    }
}

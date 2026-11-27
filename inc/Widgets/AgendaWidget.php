<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Agenda;
use KoperasiKita\Icons\Icon;
use WP_Query;
use WP_Widget;

/**
 * Agenda widget: upcoming cooperative events as a card that follows
 * the site colour scheme, matching the "AGENDA KOPERASI" design - a
 * date badge (day + month) on the left, the title and a pin +
 * location on the right. Status stays subtle: a live event gets a
 * green dot on its date badge, a finished event is dimmed, upcoming
 * events are plain. Upcoming items come first, soonest first; when
 * "Show past events" is on, finished items fill the remaining slots,
 * most recent first. Items without a date are never shown - the badge
 * has nothing to print. The header always renders: an empty title
 * falls back to "Agenda Koperasi", so the card (and its block-editor
 * preview) never loses its header.
 */
final class AgendaWidget extends WP_Widget
{
    private const DEFAULT_COUNT = 5;
    private const MAX_COUNT = 10;
    private const DEFAULT_ICON = 'users';
    private const DEFAULT_STYLE = 'auto';
    private const DEFAULT_BG_COLOR = '#ffffff';
    private const DEFAULT_ACCENT_COLOR = '#0b6b3a';

    /** Allowed Card style values; anything else falls back to 'auto'. */
    private const STYLES = ['auto', 'dark', 'light', 'custom'];

    public function __construct()
    {
        parent::__construct(
            'kk_agenda',
            __('Koperasi Kita Agenda', 'koperasikita'),
            [
                'description' => __('Upcoming cooperative events as a card that follows the site colour scheme; live events get a green dot, finished events are dimmed.', 'koperasikita'),
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
        $showIcon = !isset($instance['show_icon']) || (bool) $instance['show_icon'];
        $icon = self::sanitizeIcon(isset($instance['icon']) ? (string) $instance['icon'] : self::DEFAULT_ICON);
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $bgColor = self::sanitizeColor(isset($instance['bg_color']) ? (string) $instance['bg_color'] : '');
        $accentColor = self::sanitizeColor(isset($instance['accent_color']) ? (string) $instance['accent_color'] : '');
        $linkUrl = self::resolveLink(isset($instance['link_to']) ? (int) $instance['link_to'] : 0);

        $items = self::items($count, $showPast);

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::NO_BORDER), WidgetSpacing::margins($instance));

        // The title lives inside the card (icon + "AGENDA KOPERASI"),
        // so this widget deliberately skips WidgetHeader::render(). The
        // container's top rule belongs to the title style, and this
        // widget has none - so the wrapper carries no top border, on
        // the frontend and in the editor preview alike. The template
        // renders the card even with no items - the empty state is an
        // honest line inside the same card, never fake events.
        get_template_part('template-parts/agenda/widget', null, [
            'title' => $title,
            'items' => $items,
            'show_icon' => $showIcon,
            'icon' => $icon,
            'style' => $style,
            'bg_color' => $bgColor,
            'accent_color' => $accentColor,
            'link_url' => $linkUrl,
        ]);

        echo $args['after_widget'] ?? '';
    }

    /**
     * The Card style options for the settings select.
     *
     * @return array<string, string>
     */
    public static function styleOptions(): array
    {
        return [
            'auto' => __('Auto', 'koperasikita'),
            'dark' => __('Dark Green', 'koperasikita'),
            'light' => __('Light', 'koperasikita'),
            'custom' => __('Choose Own', 'koperasikita'),
        ];
    }

    /**
     * Clamp a saved Card style to the allowed list.
     */
    public static function sanitizeStyle(string $style): string
    {
        return in_array($style, self::STYLES, true) ? $style : self::DEFAULT_STYLE;
    }

    /**
     * Clamp a saved Choose Own colour to a hex colour; anything
     * invalid (or empty) falls back to '' - unset, so the card uses
     * the theme colour for that slot instead of a broken value.
     */
    public static function sanitizeColor(string $color): string
    {
        $color = sanitize_hex_color($color);

        return is_string($color) ? $color : '';
    }

    /**
     * Clamp a saved icon name to a real icon; anything unknown (or an
     * empty string, e.g. after "Remove icon" in the picker) falls back
     * to the default header icon.
     */
    public static function sanitizeIcon(string $icon): string
    {
        return Icon::exists($icon) ? $icon : self::DEFAULT_ICON;
    }

    /**
     * The URL the card title links to, or '' when the widget links
     * nowhere: no link saved, or the linked post is gone, trashed,
     * private, or not a post/page. Checked at render (and at save),
     * so a link never points at a dead or private post.
     */
    public static function resolveLink(int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return '';
        }

        if (!in_array($post->post_type, ['post', 'page'], true) || $post->post_status !== 'publish') {
            return '';
        }

        $url = get_permalink($post);

        return is_string($url) ? $url : '';
    }

    /**
     * AJAX: search published posts and pages by title for the
     * "Link to" field (kk_agenda_link_search). Title-only: the plain
     * `s` search also matches post content, which would surface posts
     * whose title never mentions the typed name.
     *
     * Called through Api\Ajax, which already verified the nonce.
     *
     * @return array{results: array<int, array{id: int, title: string, type: string, url: string}>}
     */
    public static function ajaxSearchLink(): array
    {
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        if (mb_strlen($query) < 2) {
            return ['results' => []];
        }

        // Swap the search SQL for a post_title LIKE, scoped to this
        // one query so no other query on the page is affected.
        $filter = static function (string $search, WP_Query $wpQuery) use ($query): string {
            global $wpdb;

            $like = '%' . $wpdb->esc_like($query) . '%';

            return $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", $like);
        };

        add_filter('posts_search', $filter, 10, 2);

        try {
            $found = new WP_Query([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                's' => $query,
                'posts_per_page' => 5,
                'orderby' => 'title',
                'order' => 'ASC',
                'no_found_rows' => true,
            ]);
        } finally {
            remove_filter('posts_search', $filter, 10);
        }

        $results = [];

        foreach ($found->posts as $post) {
            $type = get_post_type_object($post->post_type);

            $results[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'type' => $type instanceof \WP_Post_Type ? $type->labels->singular_name : $post->post_type,
                'url' => (string) get_permalink($post),
            ];
        }

        return ['results' => $results];
    }

    /**
     * Upcoming items first (soonest first), then - when $showPast -
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
     * "upcoming" before it starts. A dateless item returns null - the
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

        $displayTs = Agenda::eventTimestamp($meta['start']);

        return [
            'title' => get_the_title($postId),
            'url' => (string) get_permalink($postId),
            'day' => $displayTs > 0 ? wp_date('d', $displayTs) : '',
            'month' => $displayTs > 0 ? wp_date('M', $displayTs) : '',
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
        $showIcon = !isset($instance['show_icon']) || (bool) $instance['show_icon'];
        $icon = self::sanitizeIcon(isset($instance['icon']) ? (string) $instance['icon'] : self::DEFAULT_ICON);
        $style = self::sanitizeStyle(isset($instance['style']) ? (string) $instance['style'] : self::DEFAULT_STYLE);
        $bgColor = self::sanitizeColor(isset($instance['bg_color']) ? (string) $instance['bg_color'] : '');
        $accentColor = self::sanitizeColor(isset($instance['accent_color']) ? (string) $instance['accent_color'] : '');
        $linkId = isset($instance['link_to']) ? (int) $instance['link_to'] : 0;
        $linkTitle = '';
        $linkType = '';

        if ($linkId > 0) {
            $linkPost = get_post($linkId);

            // A link that no longer resolves (trashed, private, wrong
            // type) shows as unset; update() clears it on the next
            // save instead of keeping a dead id.
            if ($linkPost instanceof \WP_Post && in_array($linkPost->post_type, ['post', 'page'], true) && $linkPost->post_status === 'publish') {
                $linkTitle = get_the_title($linkPost);
                $typeObject = get_post_type_object($linkPost->post_type);
                $linkType = $typeObject instanceof \WP_Post_Type ? $typeObject->labels->singular_name : $linkPost->post_type;
            } else {
                $linkId = 0;
            }
        }

        // The icon picker reuses the menu picker's data attributes
        // (data-kk-menu-icon-input/preview/name) with this field's id
        // as the key, so the shared popup writes here exactly like it
        // writes a menu item - see initMenuIconPickers in admin.ts.
        $iconFieldId = $this->get_field_id('icon');
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
        <?php // No header-style field: the title renders inside the card. ?>
        <?php WidgetSpacing::field($this, $instance); ?>
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
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('show_icon')); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($this->get_field_id('show_icon')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('show_icon')); ?>"
                    value="1"
                    <?php checked($showIcon); ?>
                    data-kk-agenda-icon-toggle
                >
                <?php esc_html_e('Show icon', 'koperasikita'); ?>
            </label>
        </p>
        <p class="description description-wide kk-menu-icon-field kk-agenda-icon-field"<?php echo $showIcon ? '' : ' hidden'; ?> data-kk-agenda-icon-field>
            <span class="kk-menu-icon-field-label"><?php esc_html_e('Icon', 'koperasikita'); ?></span>
            <span class="kk-menu-icon-row">
                <button type="button" class="button" data-kk-widget-icon-pick="<?php echo esc_attr($iconFieldId); ?>">
                    <?php esc_html_e('Choose icon', 'koperasikita'); ?>
                </button>
            </span>
            <span class="kk-menu-icon-row kk-menu-icon-row--preview">
                <span class="kk-menu-icon-preview" data-kk-menu-icon-preview="<?php echo esc_attr($iconFieldId); ?>"><?php echo Icon::svg($icon, 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            </span>
            <span class="kk-menu-icon-name" data-kk-menu-icon-name="<?php echo esc_attr($iconFieldId); ?>"><?php echo esc_html($icon); ?></span>
            <input type="hidden" name="<?php echo esc_attr($this->get_field_name('icon')); ?>" value="<?php echo esc_attr($icon); ?>" data-kk-menu-icon-input="<?php echo esc_attr($iconFieldId); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                <?php esc_html_e('Card style', 'koperasikita'); ?>
            </label>
            <select
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('style')); ?>"
                name="<?php echo esc_attr($this->get_field_name('style')); ?>"
                data-kk-agenda-style-select
            >
                <?php foreach (self::styleOptions() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($style, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
        // Choose Own colours: the visible swatch is only the picker -
        // the hidden input holds the real value (empty = fall back to
        // the theme colour), and the x button clears it back to empty.
        // Same pattern as the menu icon colour in MenuIcons. The
        // whole block is hidden unless the style is Choose Own; see
        // initAgendaWidgetFields in admin.ts.
        $colorRows = [
            'bg_color' => ['label' => __('Background', 'koperasikita'), 'value' => $bgColor, 'default' => self::DEFAULT_BG_COLOR],
            'accent_color' => ['label' => __('Accent text', 'koperasikita'), 'value' => $accentColor, 'default' => self::DEFAULT_ACCENT_COLOR],
        ];
        ?>
        <div class="kk-agenda-custom-colors" data-kk-agenda-custom-colors<?php echo $style === 'custom' ? '' : ' hidden'; ?>>
            <?php foreach ($colorRows as $key => $row) : ?>
                <p>
                    <span class="kk-menu-icon-field-label"><?php echo esc_html($row['label']); ?></span>
                    <span class="kk-agenda-color-row">
                        <span class="kk-agenda-color-wrap<?php echo '' === $row['value'] ? ' is-empty' : ''; ?>" data-kk-agenda-color-wrap="<?php echo esc_attr($key); ?>">
                            <input type="color" class="kk-agenda-color" value="<?php echo esc_attr('' !== $row['value'] ? $row['value'] : $row['default']); ?>" data-kk-agenda-color="<?php echo esc_attr($key); ?>" aria-label="<?php echo esc_attr($row['label']); ?>">
                        </span>
                        <button type="button" class="kk-agenda-color-clear" data-kk-agenda-color-clear="<?php echo esc_attr($key); ?>" title="<?php esc_attr_e('Clear color', 'koperasikita'); ?>"<?php echo '' === $row['value'] ? ' hidden' : ''; ?>>&times;</button>
                        <span class="kk-agenda-color-hex" data-kk-agenda-color-hex="<?php echo esc_attr($key); ?>"><?php echo esc_html($row['value']); ?></span>
                    </span>
                    <input type="hidden" name="<?php echo esc_attr($this->get_field_name($key)); ?>" value="<?php echo esc_attr($row['value']); ?>" data-kk-agenda-color-input="<?php echo esc_attr($key); ?>">
                </p>
            <?php endforeach; ?>
        </div>
        <p>
            <span class="kk-menu-icon-field-label"><?php esc_html_e('Link to', 'koperasikita'); ?></span>
            <input
                type="search"
                class="widefat"
                autocomplete="off"
                placeholder="<?php echo esc_attr__('Search posts and pages by title…', 'koperasikita'); ?>"
                aria-label="<?php esc_attr_e('Search posts and pages by title', 'koperasikita'); ?>"
                data-kk-agenda-link-search
            >
            <span class="description">
                <?php esc_html_e('The card title links to this post or page.', 'koperasikita'); ?>
            </span>
        </p>
        <div class="kk-term-picker-list" data-kk-agenda-link-results hidden></div>
        <p class="kk-agenda-link-chosen" data-kk-agenda-link-chosen<?php echo $linkId > 0 ? '' : ' hidden'; ?>>
            <span class="kk-agenda-link-chosen-title" data-kk-agenda-link-chosen-title><?php echo esc_html($linkTitle); ?></span>
            <span class="description" data-kk-agenda-link-chosen-type><?php echo esc_html($linkType); ?></span>
            <button type="button" class="button-link" data-kk-agenda-link-clear><?php esc_html_e('Remove link', 'koperasikita'); ?></button>
        </p>
        <input type="hidden" name="<?php echo esc_attr($this->get_field_name('link_to')); ?>" value="<?php echo esc_attr((string) $linkId); ?>" data-kk-agenda-link-input>
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
        $instance['show_icon'] = !empty($new['show_icon']);
        $instance['icon'] = self::sanitizeIcon(isset($new['icon']) ? sanitize_text_field((string) $new['icon']) : self::DEFAULT_ICON);
        $instance['style'] = self::sanitizeStyle(isset($new['style']) ? sanitize_text_field((string) $new['style']) : self::DEFAULT_STYLE);
        $instance['bg_color'] = self::sanitizeColor(isset($new['bg_color']) ? sanitize_text_field((string) $new['bg_color']) : '');
        $instance['accent_color'] = self::sanitizeColor(isset($new['accent_color']) ? sanitize_text_field((string) $new['accent_color']) : '');
        // A link that no longer resolves is stored as none - the
        // widget never keeps a dead id.
        $instance['link_to'] = isset($new['link_to']) && self::resolveLink((int) $new['link_to']) !== '' ? (int) $new['link_to'] : 0;
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

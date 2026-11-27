<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Icons\Icon;
use WP_Widget;

/**
 * Quote widget: one quote card for any widget area - a dark warm
 * card with the quote in large serif type, the author with an
 * initials circle, and an optional gold link below it.
 */
final class QuoteWidget extends WP_Widget
{
    /** @var array<int, string> Leading honorifics skipped when deriving initials. */
    private const HONORIFICS = ['dr', 'dra', 'prof', 'ir', 'h', 'hj'];

    public function __construct()
    {
        parent::__construct(
            'kk_quote',
            __('KK Quote', 'koperasikita'),
            [
                'description' => __('A single quote card, for any widget area.', 'koperasikita'),
                'classname' => 'kk-quote-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /**
     * The author's initials: first letters of the first two words,
     * after leading honorifics (so "Dr. Dewi Prasetyowati" is DP,
     * not DD). One word gives one initial, an empty name gives none.
     */
    public static function initials(string $author): string
    {
        $words = preg_split('/\s+/', trim($author)) ?: [];
        $words = array_values(array_filter($words, static fn ($word) => $word !== ''));

        while ($words !== [] && in_array(strtolower(rtrim((string) $words[0], '.')), self::HONORIFICS, true)) {
            array_shift($words);
        }

        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $quote = isset($instance['quote']) ? (string) $instance['quote'] : '';

        // An empty quote is not a card: render nothing at all, not
        // even the container or the title.
        if (trim($quote) === '') {
            return;
        }

        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $author = isset($instance['author']) ? (string) $instance['author'] : '';
        $role = isset($instance['role']) ? (string) $instance['role'] : '';
        $linkUrl = isset($instance['link_url']) ? (string) $instance['link_url'] : '';
        $linkLabel = isset($instance['link_label']) ? (string) $instance['link_label'] : '';

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));
        ?>
        <figure class="kk-quote">
            <blockquote class="kk-quote-text"><?php echo nl2br(esc_html($quote)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></blockquote>
            <?php if ($author !== '') : ?>
                <figcaption class="kk-quote-author">
                    <span class="kk-quote-initials" aria-hidden="true"><?php echo esc_html(self::initials($author)); ?></span>
                    <span class="kk-quote-author-text">
                        <span class="kk-quote-name"><?php echo esc_html($author); ?></span>
                        <?php if ($role !== '') : ?>
                            <span class="kk-quote-role"><?php echo esc_html($role); ?></span>
                        <?php endif; ?>
                    </span>
                </figcaption>
            <?php endif; ?>
            <?php if ($linkUrl !== '') : ?>
                <a class="kk-quote-link" href="<?php echo esc_url($linkUrl); ?>">
                    <?php echo esc_html($linkLabel !== '' ? $linkLabel : __('Read more', 'koperasikita')); ?>
                    <?php echo Icon::svg('chevron-right', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
            <?php endif; ?>
        </figure>
        <?php

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $quote = isset($instance['quote']) ? (string) $instance['quote'] : '';
        $author = isset($instance['author']) ? (string) $instance['author'] : '';
        $role = isset($instance['role']) ? (string) $instance['role'] : '';
        $linkUrl = isset($instance['link_url']) ? (string) $instance['link_url'] : '';
        $linkLabel = isset($instance['link_label']) ? (string) $instance['link_label'] : '';
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
        <?php WidgetHeader::field($this, $instance); ?>
        <?php WidgetSpacing::field($this, $instance); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('quote')); ?>">
                <?php esc_html_e('Quote', 'koperasikita'); ?>
            </label>
            <textarea
                class="widefat"
                rows="4"
                id="<?php echo esc_attr($this->get_field_id('quote')); ?>"
                name="<?php echo esc_attr($this->get_field_name('quote')); ?>"
            ><?php echo esc_textarea($quote); ?></textarea>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('author')); ?>">
                <?php esc_html_e('Author', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('author')); ?>"
                name="<?php echo esc_attr($this->get_field_name('author')); ?>"
                value="<?php echo esc_attr($author); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('role')); ?>">
                <?php esc_html_e('Role', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('role')); ?>"
                name="<?php echo esc_attr($this->get_field_name('role')); ?>"
                value="<?php echo esc_attr($role); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('link_url')); ?>">
                <?php esc_html_e('Link URL', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('link_url')); ?>"
                name="<?php echo esc_attr($this->get_field_name('link_url')); ?>"
                value="<?php echo esc_attr($linkUrl); ?>"
            >
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('link_label')); ?>">
                <?php esc_html_e('Link label', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="text"
                id="<?php echo esc_attr($this->get_field_id('link_label')); ?>"
                name="<?php echo esc_attr($this->get_field_name('link_label')); ?>"
                value="<?php echo esc_attr($linkLabel); ?>"
            >
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
        $instance['quote'] = isset($new['quote']) ? sanitize_textarea_field((string) $new['quote']) : '';
        $instance['author'] = isset($new['author']) ? sanitize_text_field((string) $new['author']) : '';
        $instance['role'] = isset($new['role']) ? sanitize_text_field((string) $new['role']) : '';
        $instance['link_url'] = isset($new['link_url']) ? esc_url_raw((string) $new['link_url']) : '';
        $instance['link_label'] = isset($new['link_label']) ? sanitize_text_field((string) $new['link_label']) : '';
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

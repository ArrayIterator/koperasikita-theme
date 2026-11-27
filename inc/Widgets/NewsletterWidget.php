<?php

declare(strict_types=1);

namespace KoperasiKita\Widgets;

use KoperasiKita\Features\Subscribe;
use WP_Widget;

/**
 * Newsletter widget: the same subscribe form as the footer and the
 * newsletter section, for any widget area. The form markup lives in
 * Subscribe::renderForm() and the submit runs through the same
 * global handler, so this widget adds no submit logic of its own.
 */
final class NewsletterWidget extends WP_Widget
{
    /** The copy line under the title, shared with the footer and the newsletter section. */
    public static function defaultText(): string
    {
        return __('News, announcements and market updates from the cooperative, delivered to your inbox.', 'koperasikita');
    }

    public function __construct()
    {
        parent::__construct(
            'kk_newsletter',
            __('KK Newsletter', 'koperasikita'),
            [
                'description' => __('The newsletter subscribe form, for any widget area.', 'koperasikita'),
                'classname' => 'kk-newsletter-widget',
                'customize_selective_refresh' => true,
            ]
        );
    }

    /** @param array<string, mixed> $args Display arguments. @param array<string, mixed> $instance Widget settings. */
    public function widget($args, $instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : '';
        $text = isset($instance['text']) ? (string) $instance['text'] : '';

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance));

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));

        if ($text !== '') {
            ?>
            <p class="kk-newsletter-widget-text"><?php echo nl2br(esc_html($text)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
            <?php
        }

        echo Subscribe::renderForm(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo $args['after_widget'] ?? '';
    }

    /** @param array<string, mixed> $instance Widget settings. */
    public function form($instance): void
    {
        $title = isset($instance['title']) ? (string) $instance['title'] : __('Stay informed', 'koperasikita');
        $text = isset($instance['text']) ? (string) $instance['text'] : self::defaultText();
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
            <label for="<?php echo esc_attr($this->get_field_id('text')); ?>">
                <?php esc_html_e('Text', 'koperasikita'); ?>
            </label>
            <textarea
                class="widefat"
                rows="3"
                id="<?php echo esc_attr($this->get_field_id('text')); ?>"
                name="<?php echo esc_attr($this->get_field_name('text')); ?>"
            ><?php echo esc_textarea($text); ?></textarea>
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
        $instance['text'] = isset($new['text']) ? sanitize_textarea_field((string) $new['text']) : '';
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

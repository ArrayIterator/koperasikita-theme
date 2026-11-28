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

    /** Default quote font size in px: the nearest whole px to the
     * 1.175rem the CSS uses today (18.8px at the 16px root). The
     * default card emits no inline size, so it renders the CSS
     * 1.175rem exactly. */
    private const DEFAULT_FONT_SIZE = 19;
    private const MIN_FONT_SIZE = 12;
    private const MAX_FONT_SIZE = 48;

    /** Theme defaults for the card colours, as the CSS defines them. */
    private const DEFAULT_BG_COLOR = '#3A2D1E';
    private const DEFAULT_TEXT_COLOR = '#F5EEDD';
    private const DEFAULT_ROLE_COLOR = '#e9dabe';
    private const DEFAULT_LINK_COLOR = '#e5e9cc';

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

    /**
     * Clamp a saved colour to a hex colour; anything invalid (or
     * empty) falls back to '' - unset, so the card uses the theme
     * colour for that slot instead of a broken value.
     */
    public static function sanitizeColor(string $color): string
    {
        $color = sanitize_hex_color($color);

        return is_string($color) ? $color : '';
    }

    /**
     * Clamp a saved font size to the allowed range; an empty value
     * falls back to the default size.
     */
    public static function sanitizeFontSize(mixed $value): int
    {
        if ('' === $value || null === $value) {
            return self::DEFAULT_FONT_SIZE;
        }

        return max(self::MIN_FONT_SIZE, min(self::MAX_FONT_SIZE, (int) $value));
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
        $fontSize = self::sanitizeFontSize($instance['font_size'] ?? self::DEFAULT_FONT_SIZE);
        $italic = !empty($instance['italic']);
        $bgColor = self::sanitizeColor(isset($instance['bg_color']) ? (string) $instance['bg_color'] : '');
        $textColor = self::sanitizeColor(isset($instance['text_color']) ? (string) $instance['text_color'] : '');
        $roleColor = self::sanitizeColor(isset($instance['role_color']) ? (string) $instance['role_color'] : '');
        $linkColor = self::sanitizeColor(isset($instance['link_color']) ? (string) $instance['link_color'] : '');

        // Only values that differ from the theme defaults become
        // inline vars; a default card renders exactly as before,
        // with no style attribute at all.
        $vars = [];

        if ($fontSize !== self::DEFAULT_FONT_SIZE) {
            $vars[] = '--kk-quote-font-size: ' . $fontSize . 'px';
        }

        if ($italic) {
            $vars[] = '--kk-quote-font-style: italic';
        }

        if ('' !== $bgColor) {
            $vars[] = '--kk-quote-bg: ' . $bgColor;
        }

        if ('' !== $textColor) {
            $vars[] = '--kk-quote-text: ' . $textColor;
        }

        if ('' !== $roleColor) {
            $vars[] = '--kk-quote-muted: ' . $roleColor;
        }

        if ('' !== $linkColor) {
            $vars[] = '--kk-quote-link: ' . $linkColor;
        }

        $styleAttr = $vars !== [] ? ' style="' . esc_attr(implode('; ', $vars) . ';') . '"' : '';

        echo WidgetSpacing::apply(WidgetHeader::beforeWidget($args, WidgetHeader::style($instance)), WidgetSpacing::margins($instance)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        WidgetHeader::render($args, $title, WidgetHeader::style($instance));
        ?>
        <figure class="kk-quote"<?php echo $styleAttr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
        $fontSize = self::sanitizeFontSize($instance['font_size'] ?? self::DEFAULT_FONT_SIZE);
        $italic = !empty($instance['italic']);
        $bgColor = self::sanitizeColor(isset($instance['bg_color']) ? (string) $instance['bg_color'] : '');
        $textColor = self::sanitizeColor(isset($instance['text_color']) ? (string) $instance['text_color'] : '');
        $roleColor = self::sanitizeColor(isset($instance['role_color']) ? (string) $instance['role_color'] : '');
        $linkColor = self::sanitizeColor(isset($instance['link_color']) ? (string) $instance['link_color'] : '');
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
            <label for="<?php echo esc_attr($this->get_field_id('font_size')); ?>">
                <?php esc_html_e('Font size (px)', 'koperasikita'); ?>
            </label>
            <input
                class="widefat"
                type="number"
                min="<?php echo esc_attr((string) self::MIN_FONT_SIZE); ?>"
                max="<?php echo esc_attr((string) self::MAX_FONT_SIZE); ?>"
                step="1"
                id="<?php echo esc_attr($this->get_field_id('font_size')); ?>"
                name="<?php echo esc_attr($this->get_field_name('font_size')); ?>"
                value="<?php echo esc_attr((string) $fontSize); ?>"
            >
        </p>
        <p>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($this->get_field_name('italic')); ?>"
                    value="1"
                    <?php checked($italic); ?>
                >
                <?php esc_html_e('Italic', 'koperasikita'); ?>
            </label>
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
        <?php
        // The colour rows mirror the agenda widget's Choose Own rows:
        // the visible swatch is only the picker, the hidden input
        // holds the real value (empty = fall back to the theme
        // colour), and the x button clears it back to empty. No hex
        // readout: the Reset button below restores the defaults.
        $colorRows = [
            'bg_color' => ['label' => __('Background', 'koperasikita'), 'value' => $bgColor, 'default' => self::DEFAULT_BG_COLOR],
            'text_color' => ['label' => __('Text color', 'koperasikita'), 'value' => $textColor, 'default' => self::DEFAULT_TEXT_COLOR],
            'role_color' => ['label' => __('Role color', 'koperasikita'), 'value' => $roleColor, 'default' => self::DEFAULT_ROLE_COLOR],
            'link_color' => ['label' => __('Link color', 'koperasikita'), 'value' => $linkColor, 'default' => self::DEFAULT_LINK_COLOR],
        ];
        ?>
        <?php foreach ($colorRows as $key => $row) : ?>
            <p>
                <span class="kk-menu-icon-field-label"><?php echo esc_html($row['label']); ?></span>
                <span class="kk-quote-color-row">
                    <span class="kk-quote-color-wrap<?php echo '' === $row['value'] ? ' is-empty' : ''; ?>" data-kk-quote-color-wrap="<?php echo esc_attr($key); ?>">
                        <input type="color" class="kk-quote-color" value="<?php echo esc_attr('' !== $row['value'] ? $row['value'] : $row['default']); ?>" data-kk-quote-color="<?php echo esc_attr($key); ?>" aria-label="<?php echo esc_attr($row['label']); ?>">
                    </span>
                    <button type="button" class="kk-quote-color-clear" data-kk-quote-color-clear="<?php echo esc_attr($key); ?>" title="<?php esc_attr_e('Clear color', 'koperasikita'); ?>"<?php echo '' === $row['value'] ? ' hidden' : ''; ?>>&times;</button>
                </span>
                <input type="hidden" name="<?php echo esc_attr($this->get_field_name($key)); ?>" value="<?php echo esc_attr($row['value']); ?>" data-kk-quote-color-input="<?php echo esc_attr($key); ?>">
            </p>
        <?php endforeach; ?>
        <p>
            <button type="button" class="button" data-kk-quote-color-reset><?php esc_html_e('Reset', 'koperasikita'); ?></button>
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
        $instance['font_size'] = self::sanitizeFontSize($new['font_size'] ?? self::DEFAULT_FONT_SIZE);
        $instance['italic'] = !empty($new['italic']);
        $instance['bg_color'] = self::sanitizeColor(isset($new['bg_color']) ? sanitize_text_field((string) $new['bg_color']) : '');
        $instance['text_color'] = self::sanitizeColor(isset($new['text_color']) ? sanitize_text_field((string) $new['text_color']) : '');
        $instance['role_color'] = self::sanitizeColor(isset($new['role_color']) ? sanitize_text_field((string) $new['role_color']) : '');
        $instance['link_color'] = self::sanitizeColor(isset($new['link_color']) ? sanitize_text_field((string) $new['link_color']) : '');
        $instance['header_style'] = WidgetHeader::sanitize($new['header_style'] ?? WidgetHeader::CLASSIC_TOP);
        $instance = array_merge($instance, WidgetSpacing::sanitizeInstance($new, $old));

        return $instance;
    }
}

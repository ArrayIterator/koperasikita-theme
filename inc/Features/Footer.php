<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

/**
 * Footer copyright text, alignment and social links.
 *
 * The copyright line is one setting (kk_footer_text). When it is empty the
 * theme falls back to its default line; when it is set, the text is rendered
 * as-is with the {token} placeholders below replaced. Alignment and social
 * profile URLs are separate settings in the same Customizer section.
 */
final class Footer
{
    /**
     * Social networks, in display order: setting suffix => label.
     *
     * The same map drives the Customizer controls (kk_footer_social_<suffix>)
     * and the footer rendering, so the two can never drift apart.
     *
     * @var array<string, string>
     */
    public const NETWORKS = [
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'x'         => 'X',
        'youtube'   => 'YouTube',
        'whatsapp'  => 'WhatsApp',
    ];

    /**
     * Copyright alignment values.
     *
     * @var array<int, string>
     */
    public const ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * Placeholder values for the copyright text.
     *
     * Keys are the token names without braces; the rendered text uses
     * {year}, {month}, {month_number}, {day}, {date}, {site_name},
     * {site_url} and {home_url}.
     *
     * @return array<string, string>
     */
    public static function tokens(): array
    {
        return [
            'year'         => date_i18n('Y'),
            'month'        => date_i18n('F'),
            'month_number' => date_i18n('m'),
            'day'          => date_i18n('j'),
            'date'         => date_i18n((string) get_option('date_format', 'F j, Y')),
            'site_name'    => (string) get_bloginfo('name'),
            'site_url'     => site_url(),
            'home_url'     => home_url(),
        ];
    }

    /**
     * Replace {token} placeholders in a string.
     *
     * Unknown placeholders are left untouched, so a typo never eats text
     * or breaks the output.
     */
    public static function replaceTokens(string $text): string
    {
        $replacements = [];

        foreach (self::tokens() as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * The default copyright line, with the current wording.
     *
     * Returned unescaped: the caller escapes once at render time.
     */
    public static function defaultCopyrightText(): string
    {
        /* translators: %1$s: Current year. %2$s: Site name. */
        $format = __('Copyright &copy; %1$s %2$s. All rights reserved.', 'koperasikita');

        return sprintf($format, date_i18n('Y'), get_bloginfo('name'));
    }

    /**
     * The copyright line to render: the custom text with tokens replaced,
     * or the default line when the setting is empty.
     *
     * Returned unescaped: the caller escapes once at render time.
     */
    public static function copyrightText(): string
    {
        $custom = (string) get_theme_mod('kk_footer_text', '');

        if ('' === trim($custom)) {
            return self::defaultCopyrightText();
        }

        return self::replaceTokens($custom);
    }

    /**
     * The copyright alignment: left, center or right. Anything else falls
     * back to left, so a bad stored value can never produce a bad class.
     */
    public static function alignment(): string
    {
        $value = (string) get_theme_mod('kk_footer_alignment', 'left');

        return in_array($value, self::ALIGNMENTS, true) ? $value : 'left';
    }

    /**
     * The configured social profile links, in display order.
     *
     * Only networks with a URL are returned. URLs are stored through
     * esc_url_raw and escaped again at render time.
     *
     * @return array<int, array{network: string, label: string, icon: string, url: string}>
     */
    public static function socialLinks(): array
    {
        $links = [];

        foreach (self::NETWORKS as $network => $label) {
            $url = (string) get_theme_mod('kk_footer_social_' . $network, '');

            if ('' === trim($url)) {
                continue;
            }

            $links[] = [
                'network' => $network,
                'label'   => $label,
                'icon'    => 'x' === $network ? 'x-social' : $network,
                'url'     => $url,
            ];
        }

        return $links;
    }
}

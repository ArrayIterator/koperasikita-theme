<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use WP_Customize_Control;

/**
 * Typography-section control that reports the download state of the
 * currently selected Google fonts and offers the download / clear
 * actions. It renders markup only — the buttons are wired by the
 * admin bundle (src/ts/admin.ts) through the kkAdminData localize.
 */
final class FontsManagementControl extends WP_Customize_Control
{
    public $type = 'kk_fonts_status';

    public function render_content(): void
    {
        $selected = Fonts::selectedGoogleFonts();

        echo '<span class="customize-control-title">' . esc_html($this->label) . '</span>';

        echo '<div id="kk-fonts-status">';

        if ($selected === []) {
            echo '<p class="description">' . esc_html__('No Google font is selected. Pick one above to download it for offline use.', 'koperasikita') . '</p>';
        } else {
            echo '<ul>';

            foreach ($selected as $id) {
                $state = Fonts::state($id);
                $status = (string) ($state['status'] ?? 'not-downloaded');
                $label = $this->fontLabel($id);

                echo '<li><strong>' . esc_html($label) . '</strong>: ';

                if (Fonts::isDownloading($id)) {
                    echo esc_html__('Downloading', 'koperasikita');
                } elseif ($status === 'ready') {
                    echo esc_html__('Ready', 'koperasikita');
                } elseif ($status === 'failed') {
                    echo esc_html(sprintf(
                        /* translators: %s: download error. */
                        __('Failed: %s', 'koperasikita'),
                        (string) ($state['error'] ?? '')
                    ));
                } else {
                    echo esc_html__('Not downloaded yet', 'koperasikita');
                }

                echo '</li>';
            }

            echo '</ul>';
        }

        echo '<p>';
        echo '<button type="button" class="button button-primary" data-kk-font-action="download">';
        echo esc_html__('Download fonts now', 'koperasikita');
        echo '</button> ';
        echo '<button type="button" class="button" data-kk-font-action="clear">';
        echo esc_html__('Clear font cache', 'koperasikita');
        echo '</button>';
        echo '</p>';

        echo '<p class="description">' . esc_html__('Google fonts are downloaded once and served from this site. Until a font is downloaded, the site keeps using a system font.', 'koperasikita') . '</p>';

        echo '</div>';
    }

    private function fontLabel(string $id): string
    {
        $catalogue = Fonts::catalogue();

        foreach ($catalogue as $entries) {
            if (isset($entries[$id]['label'])) {
                return (string) $entries[$id]['label'];
            }
        }

        return $id;
    }
}

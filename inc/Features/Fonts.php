<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Hooks;
use KoperasiKita\Api\Response;

/**
 * Google Fonts, self-hosted — the façade.
 *
 * This class owns the catalogue, the download state, the Customizer
 * wiring and the application of the chosen fonts (enqueue + CSS
 * variables). The actual network and file work lives in FontDownloader.
 *
 * Google fonts are never loaded from Google on the live site. When a
 * Google font is picked in the Customizer, its woff2 files are
 * downloaded once from fonts.googleapis.com / fonts.gstatic.com into
 * the uploads directory (wp-content/uploads/koperasikita-fonts) and
 * served from there, with a locally rewritten @font-face stylesheet.
 * Until a font has been downloaded successfully the site keeps using
 * its system fallback stack, so a failed download can never break
 * the layout — it only means the nicer font is not applied yet.
 *
 * The catalogue below is the single source of truth: only the fonts
 * listed here can ever be selected, downloaded or enqueued, and only
 * the weights listed here are ever requested from Google. Third
 * parties may extend it through the `koperasikita/fonts/catalogue`
 * filter; malformed entries are dropped.
 */
final class Fonts
{
    public const OPTION_STATE = 'kk_fonts_state';

    public const AJAX_DOWNLOAD = 'kk_download_font';

    public const AJAX_CLEAR = 'kk_clear_fonts';

    public const DIR_NAME = 'koperasikita-fonts';

    private const LOCK_PREFIX = 'kk_fonts_downloading_';

    private const LOCK_TTL = 120;

    private const SANS_FALLBACK = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    private const SERIF_FALLBACK = "Georgia, 'Times New Roman', serif";

    /**
     * The full catalogue, keyed by role ('body' | 'headings') and then
     * by font id. Every entry has id, label, type and stack; Google
     * entries additionally carry the Google family name and the
     * weights that really exist for that family. The stack is the
     * fallback used whenever the Google font is not downloaded yet —
     * a Google stack never contains the Google family name itself.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function catalogue(): array
    {
        $sans = self::SANS_FALLBACK;
        $serif = self::SERIF_FALLBACK;

        $catalogue = [
            'body' => [
                'system' => [
                    'id' => 'system',
                    'label' => 'System UI',
                    'type' => 'system',
                    'stack' => $sans,
                ],
                'georgia' => [
                    'id' => 'georgia',
                    'label' => 'Georgia editorial serif',
                    'type' => 'system',
                    'stack' => $serif,
                ],
                'palatino' => [
                    'id' => 'palatino',
                    'label' => 'Palatino editorial serif',
                    'type' => 'system',
                    'stack' => "'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif",
                ],
                'trebuchet' => [
                    'id' => 'trebuchet',
                    'label' => 'Trebuchet MS',
                    'type' => 'system',
                    'stack' => "'Trebuchet MS', 'Segoe UI', sans-serif",
                ],
                'verdana' => [
                    'id' => 'verdana',
                    'label' => 'Verdana',
                    'type' => 'system',
                    'stack' => 'Verdana, Geneva, sans-serif',
                ],
                'arial' => [
                    'id' => 'arial',
                    'label' => 'Arial',
                    'type' => 'system',
                    'stack' => 'Arial, Helvetica, sans-serif',
                ],
                'inter' => [
                    'id' => 'inter',
                    'label' => 'Inter',
                    'type' => 'google',
                    'family' => 'Inter',
                    'weights' => [400, 600, 700],
                    'stack' => $sans,
                ],
                'roboto' => [
                    'id' => 'roboto',
                    'label' => 'Roboto',
                    'type' => 'google',
                    'family' => 'Roboto',
                    'weights' => [400, 500, 700],
                    'stack' => $sans,
                ],
                'open-sans' => [
                    'id' => 'open-sans',
                    'label' => 'Open Sans',
                    'type' => 'google',
                    'family' => 'Open Sans',
                    'weights' => [400, 600, 700],
                    'stack' => $sans,
                ],
                'source-sans' => [
                    'id' => 'source-sans',
                    'label' => 'Source Sans 3',
                    'type' => 'google',
                    'family' => 'Source Sans 3',
                    'weights' => [400, 600, 700],
                    'stack' => $sans,
                ],
                'poppins' => [
                    'id' => 'poppins',
                    'label' => 'Poppins',
                    'type' => 'google',
                    'family' => 'Poppins',
                    'weights' => [400, 500, 600, 700],
                    'stack' => $sans,
                ],
            ],
            'headings' => [
                'system-serif' => [
                    'id' => 'system-serif',
                    'label' => 'Georgia',
                    'type' => 'system',
                    'stack' => $serif,
                ],
                'system-sans' => [
                    'id' => 'system-sans',
                    'label' => 'System UI',
                    'type' => 'system',
                    'stack' => $sans,
                ],
                'palatino' => [
                    'id' => 'palatino',
                    'label' => 'Palatino',
                    'type' => 'system',
                    'stack' => "'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif",
                ],
                'playfair' => [
                    'id' => 'playfair',
                    'label' => 'Playfair Display',
                    'type' => 'google',
                    'family' => 'Playfair Display',
                    'weights' => [400, 700, 900],
                    'stack' => $serif,
                ],
                'lora' => [
                    'id' => 'lora',
                    'label' => 'Lora',
                    'type' => 'google',
                    'family' => 'Lora',
                    'weights' => [400, 700],
                    'stack' => $serif,
                ],
                'merriweather' => [
                    'id' => 'merriweather',
                    'label' => 'Merriweather',
                    'type' => 'google',
                    'family' => 'Merriweather',
                    'weights' => [400, 700, 900],
                    'stack' => $serif,
                ],
                'source-serif' => [
                    'id' => 'source-serif',
                    'label' => 'Source Serif 4',
                    'type' => 'google',
                    'family' => 'Source Serif 4',
                    'weights' => [400, 600, 700, 900],
                    'stack' => $serif,
                ],
                'pt-serif' => [
                    'id' => 'pt-serif',
                    'label' => 'PT Serif',
                    'type' => 'google',
                    'family' => 'PT Serif',
                    'weights' => [400, 700],
                    'stack' => $serif,
                ],
            ],
        ];

        $catalogue = apply_filters(Hooks::FONTS_CATALOGUE, $catalogue);

        if (!is_array($catalogue)) {
            return [];
        }

        // A filtered catalogue must still be well-formed: drop any
        // entry that cannot be selected, downloaded or enqueued.
        $clean = [];

        foreach ($catalogue as $role => $entries) {
            if (!is_string($role) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $id => $entry) {
                if (!is_string($id) || !is_array($entry)) {
                    continue;
                }

                if (!isset($entry['id'], $entry['label'], $entry['type'], $entry['stack'])) {
                    continue;
                }

                if (!is_string($entry['id']) || !is_string($entry['label'])
                    || !is_string($entry['type']) || !is_string($entry['stack'])
                ) {
                    continue;
                }

                if ($entry['type'] === 'google') {
                    if (!isset($entry['family'], $entry['weights'])
                        || !is_string($entry['family']) || !is_array($entry['weights'])
                        || $entry['weights'] === []
                    ) {
                        continue;
                    }

                    $weights = array_values(array_filter(
                        array_map('intval', $entry['weights']),
                        static fn (int $weight): bool => $weight > 0
                    ));

                    if ($weights === []) {
                        continue;
                    }

                    $entry['weights'] = $weights;
                } elseif ($entry['type'] !== 'system') {
                    continue;
                }

                $clean[$role][$id] = $entry;
            }
        }

        return $clean;
    }

    /**
     * id => label choices for a Customizer select.
     *
     * @return array<string, string>
     */
    public static function choices(string $role): array
    {
        $catalogue = self::catalogue();

        if (!isset($catalogue[$role])) {
            return [];
        }

        $choices = [];

        foreach ($catalogue[$role] as $id => $entry) {
            $choices[$id] = (string) $entry['label'];
        }

        return $choices;
    }

    public static function sanitizeBody(mixed $value): string
    {
        return self::sanitizeChoice($value, 'body', 'system');
    }

    public static function sanitizeHeadings(mixed $value): string
    {
        return self::sanitizeChoice($value, 'headings', 'system-serif');
    }

    /**
     * CSS font-family value for the currently selected body font.
     * A Google font is named only when its files are downloaded and
     * ready; otherwise the honest system fallback is returned.
     */
    public static function bodyStack(): string
    {
        return self::stackFor('body', self::sanitizeBody(get_theme_mod('kk_font_body', 'system')));
    }

    /**
     * CSS font-family value for the currently selected headings font.
     */
    public static function headingsStack(): string
    {
        return self::stackFor('headings', self::sanitizeHeadings(get_theme_mod('kk_font_headings', 'system-serif')));
    }

    public static function isGoogle(string $id): bool
    {
        $entry = self::findEntry($id);

        return $entry !== null && $entry['type'] === 'google';
    }

    /**
     * Whether a download of this font is currently in progress
     * (the transient lock set by ensureFont is still held).
     */
    public static function isDownloading(string $id): bool
    {
        $slug = self::slug($id);

        if ($slug === '') {
            return false;
        }

        return get_transient(self::LOCK_PREFIX . $slug) !== false;
    }

    /**
     * Download state for one font id.
     *
     * @return array<string, mixed>
     */
    public static function state(string $id): array
    {
        $states = self::allStates();
        $slug = self::slug($id);

        if ($slug === '' || !isset($states[$slug]) || !is_array($states[$slug])) {
            return ['status' => 'not-downloaded'];
        }

        return $states[$slug];
    }

    /**
     * Public URL of the local stylesheet for a downloaded font, or an
     * empty string when the font is not ready or its file is missing.
     */
    public static function localCssUrl(string $id): string
    {
        $slug = self::slug($id);

        if ($slug === '' || !self::isGoogle($id)) {
            return '';
        }

        $state = self::state($id);

        if (($state['status'] ?? '') !== 'ready') {
            return '';
        }

        if (!is_file(self::fontsDir() . '/' . $slug . '.css')) {
            return '';
        }

        return self::fontsUrl() . '/' . $slug . '.css';
    }

    /**
     * Google font ids currently selected as body and/or headings font,
     * deduplicated, body first.
     *
     * @return array<int, string>
     */
    public static function selectedGoogleFonts(): array
    {
        $selected = [];

        foreach ([self::sanitizeBody(get_theme_mod('kk_font_body', 'system')), self::sanitizeHeadings(get_theme_mod('kk_font_headings', 'system-serif'))] as $id) {
            if (self::isGoogle($id) && !in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        }

        return $selected;
    }

    /**
     * Data for the Customizer preview: every catalogue entry with the
     * stack the preview should apply, the local stylesheet URL (empty
     * until downloaded) and whether it is a Google font.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function previewFonts(): array
    {
        $fonts = [];

        foreach (self::catalogue() as $role => $entries) {
            foreach ($entries as $id => $entry) {
                $fonts[$id] = [
                    'stack' => self::stackFor($role, $id),
                    'cssUrl' => self::localCssUrl($id),
                    'google' => $entry['type'] === 'google',
                ];
            }
        }

        return $fonts;
    }

    /**
     * Enqueue the local stylesheet of every selected Google font that
     * is ready. Nothing is ever enqueued from Google.
     */
    public static function enqueue(): void
    {
        foreach (self::selectedGoogleFonts() as $id) {
            $url = self::localCssUrl($id);

            if ($url === '') {
                continue;
            }

            $slug = self::slug($id);
            $path = self::fontsDir() . '/' . $slug . '.css';
            $version = is_file($path) ? (int) filemtime($path) : false;

            wp_enqueue_style('kk-fonts-' . $slug, $url, ['kk-app'], $version);
        }
    }

    /**
     * Print the live font variables onto the already-enqueued kk-app
     * stylesheet. This is what applies the font choice on the live
     * site (the old body-class mechanism only ever ran inside the
     * Customizer preview).
     */
    public static function outputFontVariables(): void
    {
        $css = ':root{--kk-sans:' . self::bodyStack() . ';--kk-serif:' . self::headingsStack() . ';}';

        wp_add_inline_style('kk-app', $css);
    }

    public static function register(): void
    {
        Ajax::add(self::AJAX_DOWNLOAD, [self::class, 'ajaxDownloadFont'], false, 'edit_theme_options');
        Ajax::add(self::AJAX_CLEAR, [self::class, 'ajaxClearFonts'], false, 'edit_theme_options');
        add_action('customize_save_after', [self::class, 'ensureSelectedFonts']);
        add_action('admin_notices', [self::class, 'adminNotice']);
    }

    /**
     * Add the font-download management control to the Typography
     * section. The control itself renders no JavaScript; the buttons
     * are wired by the admin bundle through the kkAdminData localize.
     */
    public static function registerControls(\WP_Customize_Manager $wpCustomize): void
    {
        $wpCustomize->add_setting('kk_fonts_status', [
            'default' => '',
            'sanitize_callback' => static fn ($value) => '',
            'transport' => 'refresh',
        ]);

        $wpCustomize->add_control(new FontsManagementControl($wpCustomize, 'kk_fonts_status', [
            'label' => esc_html__('Google Fonts', 'koperasikita'),
            'section' => 'koperasikita_typography',
            'settings' => 'kk_fonts_status',
        ]));
    }

    /**
     * Download one Google font and store it locally.
     *
     * @return array{success: bool, code?: string, error?: string, http_status?: int, cssUrl?: string, status?: string, pending?: bool}
     */
    public static function ensureFont(string $id): array
    {
        $slug = self::slug($id);
        $entry = $slug === '' ? null : self::findEntry($slug);

        if ($entry === null || $entry['type'] !== 'google') {
            return [
                'success' => false,
                'code' => Response::ERR_INVALID_REQUEST,
                'error' => __('Unknown Google font.', 'koperasikita'),
                'http_status' => 400,
            ];
        }

        $dir = self::fontsDir();

        if (!wp_mkdir_p($dir)) {
            return [
                'success' => false,
                'code' => Response::ERR_DOWNLOAD_FAILED,
                'error' => __('Could not create the font directory.', 'koperasikita'),
                'http_status' => 500,
            ];
        }

        $cssFile = $dir . '/' . $slug . '.css';
        $state = self::state($slug);

        if (($state['status'] ?? '') === 'ready' && is_file($cssFile)) {
            return ['success' => true, 'cssUrl' => self::localCssUrl($slug), 'status' => 'ready'];
        }

        $lockKey = self::LOCK_PREFIX . $slug;

        if (get_transient($lockKey) !== false) {
            return [
                'success' => false,
                'code' => Response::ERR_PENDING,
                'error' => __('Download already in progress.', 'koperasikita'),
                'http_status' => 409,
                'pending' => true,
            ];
        }

        set_transient($lockKey, 1, self::LOCK_TTL);

        try {
            $result = FontDownloader::download($slug, $entry, $dir);

            self::setState($slug, [
                'status' => 'ready',
                'files' => $result['files'],
                'cssUrl' => self::fontsUrl() . '/' . $slug . '.css',
                'error' => '',
                'downloaded_at' => time(),
            ]);

            return ['success' => true, 'cssUrl' => self::localCssUrl($slug), 'status' => 'ready'];
        } catch (ApiException $exception) {
            FontDownloader::deleteFontFiles($dir, $slug);
            self::setState($slug, [
                'status' => 'failed',
                'files' => [],
                'cssUrl' => '',
                'error' => $exception->getMessage(),
                'downloaded_at' => time(),
            ]);

            return [
                'success' => false,
                'code' => $exception->errorCode(),
                'error' => $exception->getMessage(),
                'http_status' => $exception->httpStatus(),
            ];
        } catch (\Exception $exception) {
            FontDownloader::deleteFontFiles($dir, $slug);
            self::setState($slug, [
                'status' => 'failed',
                'files' => [],
                'cssUrl' => '',
                'error' => $exception->getMessage(),
                'downloaded_at' => time(),
            ]);

            return [
                'success' => false,
                'code' => Response::ERR_DOWNLOAD_FAILED,
                'error' => $exception->getMessage(),
                'http_status' => 500,
            ];
        } finally {
            delete_transient($lockKey);
        }
    }

    /**
     * AJAX: download one selected Google font.
     *
     * Called through Api\Ajax, which already verified the nonce and
     * the `edit_theme_options` capability. Returns the success `data`
     * array, or throws on failure.
     *
     * @return array{cssUrl: string, status: string}
     */
    public static function ajaxDownloadFont(): array
    {
        $id = isset($_POST['font']) ? sanitize_text_field(wp_unslash($_POST['font'])) : '';
        $result = self::ensureFont($id);

        if (!($result['success'] ?? false)) {
            throw new ApiException(
                (string) ($result['code'] ?? Response::ERR_DOWNLOAD_FAILED),
                (string) ($result['error'] ?? __('Download failed.', 'koperasikita')),
                (int) ($result['http_status'] ?? 500)
            );
        }

        return [
            'cssUrl' => (string) ($result['cssUrl'] ?? ''),
            'status' => (string) ($result['status'] ?? 'ready'),
        ];
    }

    /**
     * AJAX: delete every downloaded font file and forget the state.
     * Only ever touches files inside the fonts upload directory.
     *
     * Called through Api\Ajax, which already verified the nonce and
     * the `edit_theme_options` capability.
     *
     * @return array{status: string}
     */
    public static function ajaxClearFonts(): array
    {
        self::clearAllFonts();

        return ['status' => 'cleared'];
    }

    /**
     * Safety net after a Customizer save: make sure the fonts that
     * are now selected actually exist on disk.
     */
    public static function ensureSelectedFonts(): void
    {
        foreach (self::selectedGoogleFonts() as $id) {
            self::ensureFont($id);
        }
    }

    /**
     * Warn in the admin when a selected Google font failed to
     * download. The site itself is unaffected — it keeps using the
     * system fallback — this only tells the admin what happened.
     */
    public static function adminNotice(): void
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }

        $failed = [];

        foreach (self::selectedGoogleFonts() as $id) {
            $state = self::state($id);

            if (($state['status'] ?? '') === 'failed') {
                $entry = self::findEntry($id);
                $failed[] = [
                    'label' => $entry !== null ? (string) $entry['label'] : $id,
                    'error' => (string) ($state['error'] ?? ''),
                ];
            }
        }

        if ($failed === []) {
            return;
        }

        $url = admin_url('customize.php?autofocus[section]=koperasikita_typography');

        echo '<div class="notice notice-warning"><p>';

        foreach ($failed as $font) {
            echo esc_html(sprintf(
                /* translators: 1: font name, 2: download error. */
                __('The %1$s font could not be downloaded (%2$s). The site is using a system font instead.', 'koperasikita'),
                $font['label'],
                $font['error']
            ));
            echo '<br>';
        }

        echo '<a href="' . esc_url($url) . '">';
        echo esc_html__('Open Typography settings', 'koperasikita');
        echo '</a></p></div>';
    }

    /**
     * Delete every downloaded font file and the stored state. The
     * directory itself is kept so the next download has somewhere
     * to go.
     */
    public static function clearAllFonts(): void
    {
        $dir = self::fontsDir();
        $realDir = realpath($dir);

        if ($realDir === false) {
            delete_option(self::OPTION_STATE);

            return;
        }

        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? realpath((string) $uploads['basedir']) : false;

        if ($base === false || strpos($realDir, $base . DIRECTORY_SEPARATOR . self::DIR_NAME) !== 0) {
            return;
        }

        FontDownloader::clearDirectory($realDir);

        delete_option(self::OPTION_STATE);
    }

    private static function stackFor(string $role, string $id): string
    {
        $catalogue = self::catalogue();
        $entry = $catalogue[$role][$id] ?? null;

        if (!is_array($entry)) {
            $entry = $catalogue[$role][$role === 'body' ? 'system' : 'system-serif'] ?? null;
        }

        if (!is_array($entry)) {
            return $role === 'body' ? self::SANS_FALLBACK : self::SERIF_FALLBACK;
        }

        if ($entry['type'] !== 'google') {
            return (string) $entry['stack'];
        }

        if (self::localCssUrl($id) === '') {
            return (string) $entry['stack'];
        }

        return "'" . str_replace("'", "\\'", (string) $entry['family']) . "', " . (string) $entry['stack'];
    }

    private static function sanitizeChoice(mixed $value, string $role, string $default): string
    {
        $catalogue = self::catalogue();

        if (!is_string($value) || !isset($catalogue[$role][$value])) {
            return $default;
        }

        return $value;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private static function findEntry(string $id): ?array
    {
        foreach (self::catalogue() as $entries) {
            if (isset($entries[$id]) && is_array($entries[$id])) {
                return $entries[$id];
            }
        }

        return null;
    }

    /**
     * Catalogue ids are already safe, but anything coming from a
     * request is re-validated here so a crafted id can never reach a
     * path, a URL or an option key.
     */
    private static function slug(string $id): string
    {
        $id = strtolower(trim($id));

        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            return '';
        }

        return $id;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function allStates(): array
    {
        $states = get_option(self::OPTION_STATE, []);

        return is_array($states) ? $states : [];
    }

    private static function setState(string $slug, array $state): void
    {
        $states = self::allStates();
        $states[$slug] = $state;

        update_option(self::OPTION_STATE, $states);
    }

    private static function fontsDir(): string
    {
        $uploads = wp_upload_dir();

        return (string) ($uploads['basedir'] ?? '') . '/' . self::DIR_NAME;
    }

    private static function fontsUrl(): string
    {
        $uploads = wp_upload_dir();

        return (string) ($uploads['baseurl'] ?? '') . '/' . self::DIR_NAME;
    }
}

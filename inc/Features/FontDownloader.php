<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Response;

/**
 * The Google Fonts download engine.
 *
 * Owns everything that touches the network or the filesystem for fonts:
 * fetching the Google css2 stylesheet, parsing it into faces, downloading
 * the woff2 files and writing them (atomically) into the fonts upload
 * directory. It knows nothing about the catalogue, the download state
 * option or the Customizer - Fonts owns those and calls this engine.
 *
 * Failures are ApiExceptions with a stable code, so callers can report
 * them honestly: `kk_upstream_failed` when Google cannot be reached or
 * answers badly, `kk_download_failed` for invalid files and write
 * failures. A half-finished download never leaves usable files behind:
 * the caller deletes the font's files on any failure.
 */
final class FontDownloader
{
    private const CSS2_ENDPOINT = 'https://fonts.googleapis.com/css2';

    private const FONT_HOST = 'fonts.gstatic.com';

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private const MIN_WOFF2_BYTES = 1024;

    private const MAX_WOFF2_BYTES = 2097152;

    /**
     * Download one Google font into the fonts directory.
     *
     * Writes every woff2 file plus the locally rewritten stylesheet,
     * atomically. On any failure throws an ApiException and the caller
     * must delete the font's files (see deleteFontFiles).
     *
     * @param array<string, mixed> $entry Catalogue entry (family + weights).
     * @return array{faces: array<int, array<string, mixed>>, css: string, files: array<int, string>}
     */
    public static function download(string $slug, array $entry, string $dir): array
    {
        $faces = self::downloadFaces($entry, $slug, $dir);
        $css = self::buildLocalCss((string) $entry['family'], $faces);

        if (!self::writeFileAtomically($dir . '/' . $slug . '.css', $css, $dir)) {
            throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('Could not write the font stylesheet.', 'koperasikita'), 500);
        }

        $files = [$slug . '.css'];

        foreach ($faces as $face) {
            $files[] = (string) $face['file'];
        }

        return [
            'faces' => $faces,
            'css' => $css,
            'files' => $files,
        ];
    }

    /**
     * Parse a Google Fonts css2 response into font faces.
     *
     * Pure function: no WordPress calls, unit-testable with plain PHP.
     * Only latin and latin-ext subsets and only the given weights are
     * kept; every other subset (cyrillic, greek, vietnamese, ...) is
     * dropped, and so is any face whose URL is not a
     * fonts.gstatic.com woff2.
     *
     * @param array<int, int> $weights
     * @return array<int, array<string, mixed>> Each face: weight (int),
     *     subset ('latin'|'latin-ext'), url, unicode-range.
     */
    public static function parseCss2(string $css, array $weights): array
    {
        $faces = [];

        if (!preg_match_all('/\/\*\s*([a-z-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/i', $css, $blocks, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($blocks as $block) {
            $subset = strtolower($block[1]);

            if ($subset !== 'latin' && $subset !== 'latin-ext') {
                continue;
            }

            $body = $block[2];

            if (!preg_match('/font-style:\s*normal/i', $body)) {
                continue;
            }

            if (!preg_match('/font-weight:\s*(\d+)/i', $body, $weightMatch)) {
                continue;
            }

            $weight = (int) $weightMatch[1];

            if (!in_array($weight, $weights, true)) {
                continue;
            }

            if (!preg_match('/src:\s*url\((https?:\/\/[^)]+)\)\s*format\(\s*[\'"]woff2[\'"]\s*\)/i', $body, $urlMatch)) {
                continue;
            }

            $url = $urlMatch[1];

            if (!self::isFontFileUrl($url)) {
                continue;
            }

            if (!preg_match('/unicode-range:\s*([^;]+);/i', $body, $rangeMatch)) {
                continue;
            }

            $range = trim($rangeMatch[1]);

            if (!preg_match('/^[Uu0-9A-Fa-f,\s+-]+$/', $range)) {
                continue;
            }

            $key = $weight . '-' . $subset;

            if (isset($faces[$key])) {
                continue;
            }

            $faces[$key] = [
                'weight' => $weight,
                'subset' => $subset,
                'url' => $url,
                'unicode-range' => $range,
            ];
        }

        $faces = array_values($faces);

        usort($faces, static function (array $a, array $b): int {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] <=> $b['weight'];
            }

            return $a['subset'] === 'latin' ? -1 : 1;
        });

        return $faces;
    }

    /**
     * Build the local @font-face stylesheet for already-downloaded
     * woff2 files. Pure function: no WordPress calls. The src URLs
     * are relative filenames in the same directory as the stylesheet,
     * so the CSS contains no Google URL at all.
     *
     * Each face needs a 'file' key (the local woff2 filename, as
     * produced by download); when it is missing the filename is
     * derived from the family name instead.
     */
    public static function buildLocalCss(string $family, array $faces): string
    {
        $css = '';
        $safeFamily = str_replace("'", "\\'", $family);

        foreach ($faces as $face) {
            $weight = (int) ($face['weight'] ?? 400);
            $subset = (string) ($face['subset'] ?? 'latin');
            $range = (string) ($face['unicode-range'] ?? '');
            $file = isset($face['file']) && is_string($face['file']) && preg_match('/^[a-z0-9-]+\.woff2$/', $face['file'])
                ? $face['file']
                : self::slug($family) . '-' . $weight . '-' . $subset . '.woff2';

            if ($range === '' || !preg_match('/^[Uu0-9A-Fa-f,\s+-]+$/', $range)) {
                continue;
            }

            $css .= "@font-face {\n";
            $css .= "  font-family: '" . $safeFamily . "';\n";
            $css .= "  font-style: normal;\n";
            $css .= '  font-weight: ' . $weight . ";\n";
            $css .= "  font-display: swap;\n";
            $css .= '  src: url(' . $file . ") format('woff2');\n";
            $css .= '  unicode-range: ' . $range . ";\n";
            $css .= "}\n";
        }

        return $css;
    }

    /**
     * Delete every file of one font (woff2 files + stylesheet).
     */
    public static function deleteFontFiles(string $dir, string $slug): void
    {
        foreach (glob($dir . '/' . $slug . '-*.woff2') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $css = $dir . '/' . $slug . '.css';

        if (is_file($css)) {
            unlink($css);
        }
    }

    /**
     * Delete every file inside the fonts directory (the directory
     * itself is kept). Only call after the caller verified the
     * directory really is the fonts upload directory.
     */
    public static function clearDirectory(string $dir): void
    {
        $entries = scandir($dir);

        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Fetch the css2 stylesheet and every woff2 it points at, writing
     * each file atomically into the fonts directory.
     *
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>> Faces with 'file' set.
     */
    private static function downloadFaces(array $entry, string $slug, string $dir): array
    {
        $weights = $entry['weights'] ?? [];
        $weights = array_map('intval', (array) $weights);

        $query = 'family=' . urlencode((string) $entry['family']) . ':wght@' . implode(';', $weights) . '&display=swap';
        $response = wp_remote_get(self::CSS2_ENDPOINT . '?' . $query, [
            'timeout' => 15,
            'headers' => ['User-Agent' => self::USER_AGENT],
        ]);

        if (is_wp_error($response)) {
            throw new ApiException(Response::ERR_UPSTREAM_FAILED, __('Could not reach Google Fonts.', 'koperasikita'), 502);
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            throw new ApiException(Response::ERR_UPSTREAM_FAILED, __('Google Fonts returned an unexpected response.', 'koperasikita'), 502);
        }

        $css = (string) wp_remote_retrieve_body($response);

        if (!str_contains($css, '@font-face') || !str_contains($css, self::FONT_HOST)) {
            throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('Google Fonts returned an unexpected stylesheet.', 'koperasikita'), 500);
        }

        $faces = self::parseCss2($css, $weights);

        if ($faces === []) {
            throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('Google Fonts returned no usable font files.', 'koperasikita'), 500);
        }

        foreach ($faces as $index => $face) {
            $file = $slug . '-' . $face['weight'] . '-' . $face['subset'] . '.woff2';
            $faces[$index]['file'] = $file;

            if (!self::isFontFileUrl((string) $face['url'])) {
                throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('Refused an unexpected font file URL.', 'koperasikita'), 500);
            }

            $fileResponse = wp_remote_get((string) $face['url'], [
                'timeout' => 20,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ]);

            if (is_wp_error($fileResponse)) {
                throw new ApiException(Response::ERR_UPSTREAM_FAILED, __('Could not download the font file.', 'koperasikita'), 502);
            }

            if ((int) wp_remote_retrieve_response_code($fileResponse) !== 200) {
                throw new ApiException(Response::ERR_UPSTREAM_FAILED, __('The font file could not be downloaded.', 'koperasikita'), 502);
            }

            $bytes = (string) wp_remote_retrieve_body($fileResponse);
            $size = strlen($bytes);

            if (substr($bytes, 0, 4) !== 'wOF2' || $size < self::MIN_WOFF2_BYTES || $size > self::MAX_WOFF2_BYTES) {
                throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('The downloaded font file is not a valid woff2 file.', 'koperasikita'), 500);
            }

            if (!self::writeFileAtomically($dir . '/' . $file, $bytes, $dir)) {
                throw new ApiException(Response::ERR_DOWNLOAD_FAILED, __('Could not save the font file.', 'koperasikita'), 500);
            }
        }

        return $faces;
    }

    /**
     * Write through a temporary file in the same directory and rename
     * into place, so a half-written file can never be served as final.
     * Refuses any path outside the given fonts directory.
     */
    private static function writeFileAtomically(string $path, string $contents, string $dir): bool
    {
        $realDir = realpath($dir);

        if ($realDir === false) {
            return false;
        }

        $parent = realpath(dirname($path));

        if ($parent === false || $parent !== $realDir) {
            return false;
        }

        if (!preg_match('/^[a-z0-9-]+\.(woff2|css)$/', basename($path))) {
            return false;
        }

        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Only ever https://fonts.gstatic.com/... - anything else is
     * refused, so a crafted stylesheet can never turn the download
     * into a request to another host.
     */
    private static function isFontFileUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === self::FONT_HOST
            && str_ends_with(strtolower((string) ($parts['path'] ?? '')), '.woff2');
    }

    private static function slug(string $id): string
    {
        $id = strtolower(trim($id));

        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            return '';
        }

        return $id;
    }
}

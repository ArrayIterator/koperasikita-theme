<?php

declare(strict_types=1);

namespace KoperasiKita\Features\FastSearch;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Response;
use function __;
use function get_current_user_id;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function wp_unslash;

/**
 * Chunked index-build AJAX endpoints for Fast Search.
 *
 * Registered from `FastSearch::register()`, through the theme's
 * one AJAX contract (`Ajax::add`, shared `koperasikita_nonce`,
 * Response envelope, capability `manage_options`):
 * - `kk_fast_search_start`: acquire the build lock and create a
 *   job for the missing indexes. Throws 409 (kk_pending) with
 *   the running job's progress when a build is already running,
 *   so the UI can poll the status endpoint instead of starting
 *   a second build; answers success with `already_installed`
 *   when nothing is missing.
 * - `kk_fast_search_chunk`: build ONE index (the next in the job
 *   queue). POST `job_token`. Returns the progress shape from
 *   `IndexManager::runNextChunk()`; the UI calls it again until
 *   `done` is true. Throws 404 when the token does not match a
 *   running job.
 * - `kk_fast_search_status`: read-only progress of the running
 *   job (or the current index statuses when idle), so a reloaded
 *   Setup page can show a build started in another tab instead
 *   of offering a second one.
 *
 * Index builds never run on a frontend request: these are admin
 * AJAX actions only, and the feature registers no build trigger
 * anywhere else.
 */
final class FastSearchAjax
{
    /**
     * AJAX action starting a build job.
     */
    public const string AJAX_START = 'kk_fast_search_start';

    /**
     * AJAX action building the next index in the job queue.
     */
    public const string AJAX_CHUNK = 'kk_fast_search_chunk';

    /**
     * AJAX action reading build progress / index statuses.
     */
    public const string AJAX_STATUS = 'kk_fast_search_status';

    /**
     * Register the three AJAX actions.
     *
     * @return void
     */
    public static function register(): void
    {
        Ajax::add(self::AJAX_START, [self::class, 'start'], false, 'manage_options');
        Ajax::add(self::AJAX_CHUNK, [self::class, 'chunk'], false, 'manage_options');
        Ajax::add(self::AJAX_STATUS, [self::class, 'status'], false, 'manage_options');
    }

    /**
     * Start a build job for the missing indexes.
     *
     * When a build is already running, throws 409 so the UI can
     * poll the status endpoint instead of starting a second
     * build. When nothing is missing, answers success with
     * `already_installed: true`.
     *
     * @return array{already_installed: bool, job_token?: string, total?: int, queue?: array<int, string>, message: string, statuses?: array<string, bool>, ready?: bool}
     */
    public static function start(): array
    {
        $running = IndexManager::jobProgress();
        if ($running !== null) {
            throw new ApiException(Response::ERR_PENDING, __('An index build is already running.', 'koperasikita'), 409);
        }
        $job = IndexManager::startJob(get_current_user_id());
        if ($job === null) {
            // startJob() returns null in two cases: nothing is
            // missing, or a build is already running. The running
            // case threw 409 above, so re-check what is actually
            // missing instead of assuming the already-installed
            // case — a stale lock used to land here too and report
            // "already installed" while indexes were still missing.
            $missing = IndexManager::missingKeys(IndexManager::checkStatus());
            if ($missing === []) {
                return [
                    'already_installed' => true,
                    'message' => __('All search indexes are already installed.', 'koperasikita'),
                    'statuses' => IndexManager::checkStatus(),
                    'ready' => IndexManager::isReady(),
                ];
            }
            throw new ApiException(Response::ERR_INTERNAL, __('The index build could not run. Please try again.', 'koperasikita'), 500);
        }
        return [
            'already_installed' => false,
            'job_token' => $job['token'],
            'total' => $job['total'],
            'queue' => $job['queue'],
            'message' => __('Index build started.', 'koperasikita'),
        ];
    }

    /**
     * Build the next index in the running job (one index per call).
     *
     * POST: `job_token` (the token returned by the start endpoint).
     * A token that does not match the running job is a 404: the
     * job finished, expired, or belongs to another build.
     *
     * @return array<string, mixed>
     */
    public static function chunk(): array
    {
        $token = sanitize_key(self::postString('job_token'));
        if ($token === '') {
            throw new ApiException(Response::ERR_INVALID_REQUEST, __('Missing build job.', 'koperasikita'), 400);
        }
        $progress = IndexManager::runNextChunk($token);
        if ($progress === null) {
            throw new ApiException(Response::ERR_NOT_FOUND, __('Build job not found or expired.', 'koperasikita'), 404);
        }
        $progress['ready'] = IndexManager::isReady();
        if (!empty($progress['done'])) {
            $progress['statuses'] = IndexManager::checkStatus();
            $progress['message'] = !empty($progress['failed'])
                ? __('Index build finished with errors.', 'koperasikita')
                : __('All search indexes are installed.', 'koperasikita');
        }
        return $progress;
    }

    /**
     * Read the running build's progress, or the index statuses.
     *
     * Never starts or advances a build: safe to poll while another
     * tab owns the job.
     *
     * @return array{running: bool, progress?: array<string, mixed>, statuses?: array<string, bool>, ready: bool}
     */
    public static function status(): array
    {
        $progress = IndexManager::jobProgress();
        if ($progress !== null) {
            return [
                'running' => true,
                'progress' => $progress,
                'ready' => IndexManager::isReady(),
            ];
        }
        return [
            'running' => false,
            'statuses' => IndexManager::checkStatus(),
            'ready' => IndexManager::isReady(),
        ];
    }

    /**
     * Read a POST field as a sanitized string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }
}

<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

/**
 * The one AJAX contract for the whole theme.
 *
 * Every admin-ajax endpoint in the theme is registered here, through
 * `Ajax::add()`, and every one of them behaves the same way:
 *
 *  - The action name always starts with `kk_`.
 *  - Every request must carry the shared nonce (`koperasikita_nonce`,
 *    POST field `nonce`). A missing nonce answers `kk_missing_nonce`
 *    (403); a wrong one answers `kk_invalid_nonce` (403).
 *  - A mutating endpoint always names the capability it needs. A
 *    visitor without it answers `kk_forbidden` (403). Read-only
 *    endpoints pass `null` and stay open to logged-out visitors only
 *    when the feature explicitly opts in with `$nopriv = true`.
 *  - The handler returns the success `data` array, or throws an
 *    ApiException. Anything else thrown becomes `kk_internal_error`
 *    (500) with a generic message — internals never leak.
 *  - The answer is always the Response envelope, never the bare
 *    `wp_send_json_success` / `wp_send_json_error` shapes.
 *
 * Features register their endpoints from their own `register()`
 * methods; `add()` hooks them immediately, so there is no ordering
 * problem and no central list to keep in sync.
 */
final class Ajax
{
    /**
     * The one nonce action for every AJAX endpoint in the theme.
     */
    public const NONCE_ACTION = 'koperasikita_nonce';

    /**
     * Register one endpoint.
     *
     * @param callable(): array<string, mixed> $handler Returns the success `data` array, or throws ApiException.
     * @param bool $nopriv Also answer logged-out visitors. Only for read-only endpoints.
     * @param string|null $capability Required capability, or null for no capability check.
     */
    public static function add(string $action, callable $handler, bool $nopriv = false, ?string $capability = null): void
    {
        $dispatch = static function () use ($action, $handler, $capability): void {
            self::dispatch($handler, $capability);
        };

        add_action('wp_ajax_' . $action, $dispatch);

        if ($nopriv) {
            add_action('wp_ajax_nopriv_' . $action, $dispatch);
        }
    }

    /**
     * Create a nonce for the shared AJAX action.
     */
    public static function nonce(): string
    {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    /**
     * Run one endpoint: verify the nonce, check the capability, call
     * the handler, and answer with the Response envelope.
     *
     * @param callable(): array<string, mixed> $handler
     */
    public static function dispatch(callable $handler, ?string $capability = null): void
    {
        $rawNonce = $_POST['nonce'] ?? '';
        $nonce = is_string($rawNonce) ? sanitize_key(wp_unslash($rawNonce)) : '';

        if ($nonce === '') {
            Response::sendError(Response::ERR_MISSING_NONCE, __('Missing security token. Please reload the page and try again.', 'koperasikita'), 403);

            return;
        }

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            Response::sendError(Response::ERR_INVALID_NONCE, __('Security token expired. Please reload the page and try again.', 'koperasikita'), 403);

            return;
        }

        if ($capability !== null && !current_user_can($capability)) {
            Response::sendError(Response::ERR_FORBIDDEN, __('You do not have permission to do that.', 'koperasikita'), 403);

            return;
        }

        try {
            $data = $handler();

            if (!is_array($data)) {
                throw new ApiException(Response::ERR_INTERNAL, __('Unexpected response from the server.', 'koperasikita'), 500);
            }

            Response::sendSuccess($data);
        } catch (ApiException $exception) {
            Response::sendError($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        } catch (\Throwable $exception) {
            // Never leak internals: log nothing here (WP already logs
            // fatals), just answer with the generic code.
            Response::sendError(Response::ERR_INTERNAL, __('Something went wrong. Please try again later.', 'koperasikita'), 500);
        }
    }
}

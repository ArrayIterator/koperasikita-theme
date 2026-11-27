<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

/**
 * The one JSON envelope every theme endpoint answers with.
 *
 * Success:  `{"success": true, "data": {...}, "error": null}`
 * Failure:  `{"success": false, "data": null, "error": {"code": "...", "message": "..."}}`
 *
 * The error `code` is stable and machine-readable - clients branch on
 * the code, never on the message text. The message is human-readable
 * and safe to display. Both the AJAX endpoints and the REST routes use
 * this shape, so a client written against one works against the other.
 * The HTTP status travels separately (wp_send_json / WP_REST_Response
 * second argument); it is never smuggled inside the envelope.
 */
final class Response
{
    public const ERR_MISSING_NONCE = 'kk_missing_nonce';

    public const ERR_INVALID_NONCE = 'kk_invalid_nonce';

    public const ERR_FORBIDDEN = 'kk_forbidden';

    public const ERR_NOT_FOUND = 'kk_not_found';

    public const ERR_INVALID_REQUEST = 'kk_invalid_request';

    public const ERR_UPSTREAM_FAILED = 'kk_upstream_failed';

    public const ERR_DOWNLOAD_FAILED = 'kk_download_failed';

    public const ERR_PENDING = 'kk_pending';

    public const ERR_RATE_LIMITED = 'kk_rate_limited';

    public const ERR_INTERNAL = 'kk_internal_error';

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, data: array<string, mixed>, error: null}
     */
    public static function success(array $data): array
    {
        return [
            'success' => true,
            'data' => $data,
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, data: null, error: array{code: string, message: string}}
     */
    public static function error(string $code, string $message): array
    {
        return [
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * Send a success envelope over admin-ajax and stop.
     *
     * @param array<string, mixed> $data
     */
    public static function sendSuccess(array $data, int $status = 200): void
    {
        wp_send_json(self::success($data), $status);
    }

    /**
     * Send an error envelope over admin-ajax and stop.
     */
    public static function sendError(string $code, string $message, int $status = 400): void
    {
        wp_send_json(self::error($code, $message), $status);
    }

    /**
     * Turn a thrown ApiException into its error envelope.
     *
     * @return array{success: bool, data: null, error: array{code: string, message: string}}
     */
    public static function fromException(ApiException $exception): array
    {
        return self::error($exception->errorCode(), $exception->getMessage());
    }

    /**
     * A success envelope as a WP_REST_Response (HTTP 200).
     *
     * @param array<string, mixed> $data
     */
    public static function restSuccess(array $data): \WP_REST_Response
    {
        return new \WP_REST_Response(self::success($data), 200);
    }

    /**
     * An ApiException as a WP_REST_Response with its HTTP status.
     */
    public static function toRest(ApiException $exception): \WP_REST_Response
    {
        return new \WP_REST_Response(self::fromException($exception), $exception->httpStatus());
    }
}

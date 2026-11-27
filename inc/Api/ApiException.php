<?php

declare(strict_types=1);

namespace KoperasiKita\Api;

/**
 * An error that maps directly onto the public API envelope.
 *
 * Handlers throw this instead of building error responses by hand, so
 * every endpoint - AJAX or REST - reports failures with the same
 * machine-readable code and HTTP status. The message is safe to show
 * to the caller; never put internals (paths, SQL, stack traces) in it.
 */
final class ApiException extends \Exception
{
    private string $errorCode;

    private int $httpStatus;

    public function __construct(string $code, string $message, int $status = 400)
    {
        parent::__construct($message, $status);

        $this->errorCode = $code;
        $this->httpStatus = $status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

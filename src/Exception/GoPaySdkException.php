<?php

declare(strict_types=1);

namespace GoPay\Payments\Exception;

/**
 * Thrown for SDK lifecycle and configuration errors — e.g. no token available,
 * token refresh failed, or an invalid token response was received.
 *
 * Check {@see $errorCode} for a machine-readable reason.
 */
final class GoPaySdkException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorCode $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

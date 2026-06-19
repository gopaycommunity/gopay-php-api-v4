<?php

declare(strict_types=1);

namespace GoPay\Payments\Exception;

/**
 * Thrown when the GoPay API returns a non-2xx response.
 *
 * Exposes the HTTP {@see $status} code and the parsed response {@see $body}.
 */
final class GoPayHttpException extends \RuntimeException
{
    /**
     * @param int   $status HTTP status code (e.g. 401, 422).
     * @param mixed $body   Parsed JSON body, or raw text if JSON parsing failed.
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
    ) {
        parent::__construct(sprintf('GoPay API error: HTTP %d', $status));
    }
}

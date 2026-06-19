<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

/**
 * Per-request options that override SDK-level config.
 *
 * @phpstan-type Headers array<string, string>
 */
final class RequestOptions
{
    /**
     * @param array<string, string> $headers    Extra HTTP headers merged into the request.
     * @param string|null           $accessToken Override the bearer token for this single request.
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly ?string $accessToken = null,
    ) {}
}

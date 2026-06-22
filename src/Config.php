<?php

declare(strict_types=1);

namespace GoPay\Payments;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;

/**
 * Immutable configuration object for the GoPayClient.
 *
 * @phpstan-type ErrorHandler callable(GoPaySdkException|GoPayHttpException): void
 */
final class Config
{
    /**
     * @param Environment $environment     Target environment. Defaults to Sandbox.
     * @param string|null $baseUrl         Override the API base URL (e.g. for mock servers). Takes precedence over $environment.
     * @param bool        $debugLoggingEnabled Log outgoing requests and incoming responses.
     * @param (callable(GoPaySdkException|GoPayHttpException): void)|null $onError Called synchronously for every SDK/HTTP error before it propagates.
     * @param string|null $shareableKey    Shareable (public) key for browser-SDK handoff via getBrowserKeys().
     */
    public function __construct(
        public readonly Environment $environment = Environment::Sandbox,
        public readonly ?string $baseUrl = null,
        public readonly bool $debugLoggingEnabled = false,
        /** @phpstan-var (callable(GoPaySdkException|GoPayHttpException): void)|null */
        public readonly mixed $onError = null,
        public readonly ?string $shareableKey = null,
    ) {
        if ($this->onError !== null && !is_callable($this->onError)) {
            throw new GoPaySdkException(
                '[GoPaySDK] onError must be a callable.',
                ErrorCode::InvalidConfig,
            );
        }
    }

    public function resolvedBaseUrl(): string
    {
        return $this->baseUrl ?? $this->environment->baseUrl();
    }
}

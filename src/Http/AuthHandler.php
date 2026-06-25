<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Handles OAuth2 bearer-token injection, proactive refresh, and 401 retry.
 *
 * PHP is single-threaded, so no dedup/promise guard for concurrent refreshes
 * is needed (unlike the JS SDK which uses a refreshPromise sentinel).
 *
 * Token refresh is performed via a caller-supplied callable so that the actual
 * HTTP call (and its debug logging) stays inside HttpClient.
 */
final class AuthHandler
{
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly TokenStore $tokenStore,
    ) {}

    /**
     * Return a new request with the appropriate Authorization header injected.
     * Skips injection for the token endpoint itself.
     * Falls back to shareable-key Basic auth if no bearer token is available.
     *
     * @throws GoPaySdkException
     */
    public function injectAuth(
        RequestInterface $request,
        ?RequestOptions $options,
        ?string $shareableKey,
        ?string $clientId,
    ): RequestInterface {
        // Per-request override — used directly without touching the token store.
        if ($options?->accessToken !== null) {
            return $request->withHeader('Authorization', self::BEARER_PREFIX . $options->accessToken);
        }

        // Auth endpoint doesn't get Bearer (it provides Basic credentials itself).
        if (str_ends_with($request->getUri()->getPath(), '/oauth2/token')) {
            return $request;
        }

        return $this->applyCredentialAuth($request, $shareableKey, $clientId);
    }

    /**
     * Resolve and apply the best available credential: stored bearer token,
     * shareable-key Basic auth, or throw if neither is available.
     *
     * Proactive refresh is handled in requestWithRetry() via isExpiringSoon().
     *
     * @throws GoPaySdkException
     */
    private function applyCredentialAuth(
        RequestInterface $request,
        ?string $shareableKey,
        ?string $clientId,
    ): RequestInterface {
        $token = $this->tokenStore->getAccessToken();

        if ($token !== null) {
            return $request->withHeader('Authorization', self::BEARER_PREFIX . $token);
        }

        // Shareable-key fallback: Basic auth for browser-compatible requests.
        if ($shareableKey !== null && $clientId !== null && $clientId !== '') {
            $raw = $clientId . ':' . $shareableKey;

            return $request->withHeader('Authorization', 'Basic ' . base64_encode($raw));
        }

        if ($shareableKey !== null) {
            throw new GoPaySdkException(
                '[GoPaySDK] No clientId available for shareable-key auth. Call authenticate() first.',
                ErrorCode::AuthCredentialsMissing,
            );
        }

        throw new GoPaySdkException(
            '[GoPaySDK] No access token available. Call authenticate() first.',
            ErrorCode::AuthTokenMissing,
        );
    }

    /**
     * Sends the request and retries exactly once on 401 after refreshing the token.
     *
     * $refresh is a callable that performs the token refresh (provided by HttpClient
     * so the HTTP call and debug logging stay there). It is only invoked when client
     * credentials are available and the request is not the auth endpoint itself.
     *
     * Per-request accessToken overrides bypass both proactive refresh and 401 retry:
     * that token was intentionally chosen by the caller, so the stored token is not
     * the right thing to refresh against.
     *
     * @param callable():void|null $refresh
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function requestWithRetry(
        RequestInterface $request,
        ClientInterface $client,
        bool $isAuthRequest = false,
        ?RequestOptions $options = null,
        ?callable $refresh = null,
    ): \Psr\Http\Message\ResponseInterface {
        $hasOverride = $options?->accessToken !== null;

        // Proactive refresh — skip when request carries a per-request token override.
        if (!$isAuthRequest && !$hasOverride && $this->tokenStore->isExpiringSoon() && $this->tokenStore->hasClientCredentials() && $refresh !== null) {
            ($refresh)();
            $token = $this->tokenStore->getAccessToken();
            if ($token !== null) {
                $request = $request->withHeader('Authorization', self::BEARER_PREFIX . $token);
            }
        }

        $response = $client->sendRequest($request);

        // Short-circuit: not a 401, auth endpoint, per-request override, or no credentials.
        if ($response->getStatusCode() !== 401 || $isAuthRequest || $hasOverride || !$this->tokenStore->hasClientCredentials() || $refresh === null) {
            return $response;
        }

        ($refresh)();

        $freshToken = $this->tokenStore->getAccessToken();
        if ($freshToken === null) {
            return $response;
        }

        $retryRequest = $request->withHeader('Authorization', self::BEARER_PREFIX . $freshToken);
        $retryResponse = $client->sendRequest($retryRequest);

        if ($retryResponse->getStatusCode() === 401) {
            // Persistent auth failure: clear the token but preserve credentials so the
            // caller can re-authenticate without providing client ID/secret again.
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                '[GoPaySDK] Request unauthorized after token refresh. Check OAuth2 scopes.',
                ErrorCode::AuthUnauthorized,
            );
        }

        return $retryResponse;
    }
}

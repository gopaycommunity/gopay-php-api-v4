<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Handles OAuth2 bearer-token injection, proactive refresh, and 401 retry.
 *
 * PHP is single-threaded, so no dedup/promise guard for concurrent refreshes
 * is needed (unlike the JS SDK which uses a refreshPromise sentinel).
 */
final class AuthHandler
{
    private const AUTH_PATH = '/oauth2/token';
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly TokenStore $tokenStore,
        private readonly string $baseUrl,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
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
        if (str_contains((string) $request->getUri(), self::AUTH_PATH)) {
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
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function requestWithRetry(
        RequestInterface $request,
        ClientInterface $client,
        bool $isAuthRequest = false,
    ): \Psr\Http\Message\ResponseInterface {
        // Proactive refresh before the first attempt.
        if (!$isAuthRequest && $this->tokenStore->isExpiringSoon() && $this->tokenStore->hasClientCredentials()) {
            $this->refresh($client);
            $token = $this->tokenStore->getAccessToken();
            if ($token !== null) {
                $request = $request->withHeader('Authorization', self::BEARER_PREFIX . $token);
            }
        }

        $response = $client->sendRequest($request);

        // Short-circuit: not a 401, or auth endpoint, or no credentials to refresh with.
        if ($response->getStatusCode() !== 401 || $isAuthRequest || !$this->tokenStore->hasClientCredentials()) {
            return $response;
        }

        $this->refresh($client);

        $freshToken = $this->tokenStore->getAccessToken();
        if ($freshToken === null) {
            return $response;
        }

        $retryRequest = $request->withHeader('Authorization', self::BEARER_PREFIX . $freshToken);
        $retryResponse = $client->sendRequest($retryRequest);

        if ($retryResponse->getStatusCode() === 401) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                '[GoPaySDK] Request unauthorized after token refresh. Check OAuth2 scopes.',
                ErrorCode::AuthUnauthorized,
            );
        }

        return $retryResponse;
    }

    /**
     * Perform a client_credentials token refresh synchronously.
     *
     * @throws GoPaySdkException
     */
    public function refresh(ClientInterface $client): void
    {
        $clientId = $this->tokenStore->getClientId();
        $clientSecret = $this->tokenStore->getClientSecret();
        $scope = $this->tokenStore->getScope();

        if ($clientId === null || $clientSecret === null || $scope === null) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                '[GoPaySDK] Access token expired and no client credentials available. Call authenticate() again.',
                ErrorCode::AuthCredentialsMissing,
            );
        }

        $url = rtrim($this->baseUrl, '/') . self::AUTH_PATH;
        $body = http_build_query(['grant_type' => 'client_credentials', 'scope' => $scope]);
        $credentials = base64_encode($clientId . ':' . $clientSecret);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Basic ' . $credentials)
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $client->sendRequest($request);
        } catch (\Throwable $e) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                '[GoPaySDK] Token refresh failed: ' . $e->getMessage(),
                ErrorCode::AuthRefreshFailed,
                $e,
            );
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                sprintf('[GoPaySDK] Token refresh failed: HTTP %d.', $response->getStatusCode()),
                ErrorCode::AuthRefreshFailed,
            );
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                '[GoPaySDK] Invalid token response: could not parse JSON.',
                ErrorCode::AuthInvalidResponse,
            );
        }

        $accessToken = $decoded['access_token'] ?? null;
        $expiresIn = $decoded['expires_in'] ?? null;

        if (!is_string($accessToken) || $accessToken === '' || !is_int($expiresIn)) {
            $this->tokenStore->clear();
            throw new GoPaySdkException(
                '[GoPaySDK] Invalid token response: missing required fields.',
                ErrorCode::AuthInvalidResponse,
            );
        }

        $this->tokenStore->setToken($accessToken, $expiresIn);
    }
}

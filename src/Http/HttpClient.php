<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

use GoPay\Payments\Config;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\ModelInterface;
use GoPay\Payments\Generated\ObjectSerializer;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 HTTP client with GoPay auth, error mapping, and response deserialization.
 *
 * @SuppressWarnings("php:S1448")
 */
final class HttpClient
{
    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly AuthHandler $authHandler;
    private readonly TokenStore $tokenStore;
    private const APPLICATION_JSON = 'application/json';

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->tokenStore = new TokenStore();
        if ($config->shareableKey !== null) {
            $this->tokenStore->setShareableKey($config->shareableKey);
        }
        $this->authHandler = new AuthHandler($this->tokenStore);
    }

    // -------------------------------------------------------------------------
    // HTTP verbs
    // -------------------------------------------------------------------------

    /**
     * @template T of ModelInterface
     *
     * @param class-string<T> $type
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return T
     */
    public function get(string $path, string $type, ?RequestOptions $options = null): ModelInterface
    {
        $request = $this->buildRequest('GET', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        return $this->deserialize((string) $response->getBody(), $type);
    }

    /**
     * @template T of ModelInterface
     *
     * @param class-string<T> $type
     * @param array<string, mixed>|null $body
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return T
     */
    public function post(string $path, ?array $body, string $type, ?RequestOptions $options = null): ModelInterface
    {
        $encoded = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $request = $this->buildRequest('POST', $path, $encoded, $options, self::APPLICATION_JSON);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        return $this->deserialize((string) $response->getBody(), $type);
    }

    /**
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function delete(string $path, ?RequestOptions $options = null): void
    {
        $request = $this->buildRequest('DELETE', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);
    }

    /**
     * GET, returning a top-level JSON array as a list of decoded items.
     * Use for list endpoints (e.g. GET /payments/{id}/refunds → [{...}, {...}]).
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return list<array<string, mixed>>
     */
    public function getJsonList(string $path, ?RequestOptions $options = null): array
    {
        $request = $this->buildRequest('GET', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        return $this->decodeJsonList((string) $response->getBody());
    }

    /**
     * GET, returning the decoded JSON as a plain PHP array (no DTO hydration).
     * Use for endpoints whose response shape varies or is opaque (object responses).
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return array<string, mixed>
     */
    public function getArray(string $path, ?RequestOptions $options = null): array
    {
        $request = $this->buildRequest('GET', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        return $this->decodeJsonObject((string) $response->getBody());
    }

    /**
     * POST, returning the decoded JSON as a plain PHP array (no DTO hydration).
     *
     * @param array<string, mixed>|null $body
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return array<string, mixed>
     */
    public function postArray(string $path, ?array $body, ?RequestOptions $options = null): array
    {
        $encoded = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $request = $this->buildRequest('POST', $path, $encoded, $options, self::APPLICATION_JSON);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        return $this->decodeJsonObject((string) $response->getBody());
    }

    /**
     * @template T of ModelInterface
     *
     * @param class-string<T>        $type
     * @param array<string, string>  $form
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return T
     */
    public function postForm(string $path, array $form, string $type, ?RequestOptions $options = null): ModelInterface
    {
        $request = $this->buildRequest('POST', $path, http_build_query($form), $options, 'application/x-www-form-urlencoded');
        $response = $this->send($request, $options, isAuthRequest: true);
        $this->throwIfNotOk($response);

        return $this->deserialize((string) $response->getBody(), $type);
    }

    // -------------------------------------------------------------------------
    // Token / auth delegation (used by module classes)
    // -------------------------------------------------------------------------

    public function getTokenStore(): TokenStore
    {
        return $this->tokenStore;
    }

    public function authenticate(string $clientId, string $clientSecret, string $scope): void
    {
        $this->tokenStore->setClientCredentials($clientId, $clientSecret, $scope);
        try {
            $this->refresh();
        } catch (GoPaySdkException $e) {
            $this->emitError($e);
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->tokenStore->hasAccessToken();
    }

    public function logout(): void
    {
        $this->tokenStore->clear();
    }

    public function setShareableKey(string $key): void
    {
        $this->tokenStore->setShareableKey($key);
    }

    public function getShareableKey(): ?string
    {
        return $this->tokenStore->getShareableKey();
    }

    public function getClientId(): ?string
    {
        return $this->tokenStore->getClientId();
    }

    /**
     * Perform a client_credentials token refresh synchronously.
     *
     * Throws GoPaySdkException without calling emitError() — callers (authenticate(),
     * requestWithRetry() via the refresh callable) are responsible for emitting.
     *
     * On transient failures only the token is cleared (not the client credentials),
     * so long-running workers can recover on the next request without re-authenticating.
     *
     * @throws GoPaySdkException
     */
    public function refresh(): void
    {
        $clientId = $this->tokenStore->getClientId();
        $clientSecret = $this->tokenStore->getClientSecret();
        $scope = $this->tokenStore->getScope();

        if ($clientId === null || $clientSecret === null || $scope === null) {
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                '[GoPaySDK] Access token expired and no client credentials available. Call authenticate() again.',
                ErrorCode::AuthCredentialsMissing,
            );
        }

        $url = rtrim($this->config->resolvedBaseUrl(), '/') . '/oauth2/token';
        $body = http_build_query(['grant_type' => 'client_credentials', 'scope' => $scope]);
        $credentials = base64_encode($clientId . ':' . $clientSecret);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', self::APPLICATION_JSON)
            ->withHeader('Authorization', 'Basic ' . $credentials)
            ->withBody($this->streamFactory->createStream($body));

        if ($this->config->debugLoggingEnabled) {
            error_log(sprintf('[GoPaySDK] → POST %s', $url));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (\Throwable $e) {
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                '[GoPaySDK] Token refresh failed: ' . $e->getMessage(),
                ErrorCode::AuthRefreshFailed,
                $e,
            );
        }

        if ($this->config->debugLoggingEnabled) {
            error_log(sprintf('[GoPaySDK] ← %d %s', $response->getStatusCode(), $url));
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                sprintf('[GoPaySDK] Token refresh failed: HTTP %d.', $response->getStatusCode()),
                ErrorCode::AuthRefreshFailed,
            );
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                '[GoPaySDK] Invalid token response: could not parse JSON.',
                ErrorCode::AuthInvalidResponse,
            );
        }

        $accessToken = $decoded['access_token'] ?? null;
        $expiresIn = $decoded['expires_in'] ?? null;

        if (!is_string($accessToken) || $accessToken === '' || !is_int($expiresIn)) {
            $this->tokenStore->clearToken();
            throw new GoPaySdkException(
                '[GoPaySDK] Invalid token response: missing required fields.',
                ErrorCode::AuthInvalidResponse,
            );
        }

        $this->tokenStore->setToken($accessToken, $expiresIn);
    }

    // -------------------------------------------------------------------------
    // Error emission
    // -------------------------------------------------------------------------

    /**
     * Invoke the onError callback (if configured) and then throw.
     *
     * @phpstan-return never
     */
    public function emitError(\Throwable $e): never
    {
        $onError = $this->config->onError;
        if ($onError !== null && ($e instanceof GoPaySdkException || $e instanceof GoPayHttpException)) {
            $onError($e);
        }
        throw $e;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildRequest(
        string $method,
        string $path,
        ?string $body,
        ?RequestOptions $options,
        string $contentType = self::APPLICATION_JSON,
    ): \Psr\Http\Message\RequestInterface {
        $url = rtrim($this->config->resolvedBaseUrl(), '/') . '/' . ltrim($path, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', self::APPLICATION_JSON);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', $contentType)
                ->withBody($this->streamFactory->createStream($body));
        }

        // Merge per-request extra headers.
        foreach ($options !== null ? $options->headers : [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function send(
        \Psr\Http\Message\RequestInterface $request,
        ?RequestOptions $options,
        bool $isAuthRequest = false,
    ): \Psr\Http\Message\ResponseInterface {
        if ($this->config->debugLoggingEnabled) {
            error_log(sprintf('[GoPaySDK] → %s %s', $request->getMethod(), $request->getUri()));
        }

        try {
            $request = $this->authHandler->injectAuth(
                $request,
                $options,
                $this->tokenStore->getShareableKey(),
                $this->tokenStore->getClientId(),
            );

            $response = $this->authHandler->requestWithRetry(
                $request,
                $this->client,
                $isAuthRequest,
                $options,
                fn() => $this->refresh(),
            );
        } catch (GoPaySdkException $e) {
            $this->emitError($e);
        } catch (NetworkExceptionInterface $e) {
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] Network error: ' . $e->getMessage(),
                ErrorCode::NetworkError,
                $e,
            ));
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] HTTP client error: ' . $e->getMessage(),
                ErrorCode::NetworkError,
                $e,
            ));
        }

        if ($this->config->debugLoggingEnabled) {
            error_log(sprintf('[GoPaySDK] ← %d %s', $response->getStatusCode(), $request->getUri()));
        }

        return $response;
    }

    private function throwIfNotOk(\Psr\Http\Message\ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $parsedBody = json_last_error() === JSON_ERROR_NONE ? $decoded : $body;

        $this->emitError(new GoPayHttpException($status, $parsedBody));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonList(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] Failed to parse API response as JSON list.',
                ErrorCode::UnexpectedResponse,
            ));
        }

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || array_is_list($data)) {
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] Failed to parse API response as JSON object.',
                ErrorCode::UnexpectedResponse,
            ));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Deserialize a JSON response body into a typed model object via ObjectSerializer.
     *
     * @template T of ModelInterface
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function deserialize(string $json, string $type): ModelInterface
    {
        $data = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || !($data instanceof \stdClass)) {
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] Failed to parse API response as JSON.',
                ErrorCode::UnexpectedResponse,
            ));
        }

        /** @var T */
        return ObjectSerializer::deserialize($data, $type);
    }
}

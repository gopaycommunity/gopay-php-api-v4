<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

use GoPay\Payments\Config;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\ModelInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 HTTP client with GoPay auth, error mapping, and response deserialization.
 */
final class HttpClient
{
    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly AuthHandler $authHandler;
    private readonly TokenStore $tokenStore;
    private ?string $shareableKey;

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
        $this->shareableKey = $config->shareableKey;
        $this->authHandler = new AuthHandler(
            $this->tokenStore,
            $config->resolvedBaseUrl(),
            $this->requestFactory,
            $this->streamFactory,
        );
    }

    // -------------------------------------------------------------------------
    // HTTP verbs
    // -------------------------------------------------------------------------

    /**
     * @template T of ModelInterface
     * @param class-string<T> $type
     * @return T
     * @throws GoPaySdkException
     * @throws GoPayHttpException
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
     * @param class-string<T> $type
     * @param array<string, mixed>|null $body
     * @return T
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function post(string $path, ?array $body, string $type, ?RequestOptions $options = null): ModelInterface
    {
        $encoded = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $request = $this->buildRequest('POST', $path, $encoded, $options, 'application/json');
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
     * @return list<array<string, mixed>>
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getJsonList(string $path, ?RequestOptions $options = null): array
    {
        $request = $this->buildRequest('GET', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        /** @var list<array<string, mixed>> */
        return array_values((array) json_decode((string) $response->getBody(), true));
    }

    /**
     * GET, returning the decoded JSON as a plain PHP array (no DTO hydration).
     * Use for endpoints whose response shape varies or is opaque (object responses).
     *
     * @return array<string, mixed>
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getArray(string $path, ?RequestOptions $options = null): array
    {
        $request = $this->buildRequest('GET', $path, null, $options);
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        /** @var array<string, mixed> */
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * POST, returning the decoded JSON as a plain PHP array (no DTO hydration).
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function postArray(string $path, ?array $body, ?RequestOptions $options = null): array
    {
        $encoded = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $request = $this->buildRequest('POST', $path, $encoded, $options, 'application/json');
        $response = $this->send($request, $options);
        $this->throwIfNotOk($response);

        /** @var array<string, mixed> */
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @template T of ModelInterface
     * @param class-string<T>        $type
     * @param array<string, string>  $form
     * @return T
     * @throws GoPaySdkException
     * @throws GoPayHttpException
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
        $this->authHandler->refresh($this->client);
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
        $this->shareableKey = $key;
    }

    public function getShareableKey(): ?string
    {
        return $this->shareableKey;
    }

    public function getClientId(): ?string
    {
        return $this->tokenStore->getClientId();
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
        if ($this->config->onError !== null && ($e instanceof GoPaySdkException || $e instanceof GoPayHttpException)) {
            ($this->config->onError)($e);
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
        string $contentType = 'application/json',
    ): \Psr\Http\Message\RequestInterface {
        $url = rtrim($this->config->resolvedBaseUrl(), '/') . '/' . ltrim($path, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', $contentType)
                ->withBody($this->streamFactory->createStream($body));
        }

        // Merge per-request extra headers.
        foreach ($options?->headers ?? [] as $name => $value) {
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
                $this->shareableKey,
                $this->tokenStore->getClientId(),
            );

            $response = $this->authHandler->requestWithRetry(
                $request,
                $this->client,
                $this->shareableKey,
                $this->tokenStore->getClientId(),
                $isAuthRequest,
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
     * Deserialize a JSON response body into a typed model object via ModelInterface::fromArray().
     *
     * @template T of ModelInterface
     * @param class-string<T> $type
     * @return T
     */
    private function deserialize(string $json, string $type): ModelInterface
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            /** @phpstan-ignore-next-line */
            $this->emitError(new GoPaySdkException(
                '[GoPaySDK] Failed to parse API response as JSON.',
                ErrorCode::NetworkError,
            ));
        }

        /** @var array<string, mixed> $data */
        /** @var T $result */
        $result = $type::fromArray($data);

        return $result;
    }
}

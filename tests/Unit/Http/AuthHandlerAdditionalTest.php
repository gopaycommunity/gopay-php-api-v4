<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Http\AuthHandler;
use GoPay\Payments\Http\RequestOptions;
use GoPay\Payments\Http\TokenStore;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthHandlerAdditionalTest extends TestCase
{
    private MockClient $mockClient;
    private HttpFactory $factory;
    private TokenStore $tokenStore;
    private AuthHandler $handler;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new HttpFactory();
        $this->tokenStore = new TokenStore();
        $this->handler = new AuthHandler($this->tokenStore);
    }

    #[Test]
    public function injectAuthWithShareableKeyButNoClientIdThrows(): void
    {
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('No clientId available for shareable-key auth');
        $this->handler->injectAuth($request, null, 'sk_test_key', null);
    }

    #[Test]
    public function injectAuthWithShareableKeyButEmptyClientIdThrows(): void
    {
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');

        $this->expectException(GoPaySdkException::class);
        $this->handler->injectAuth($request, null, 'sk_test_key', '');
    }

    #[Test]
    public function injectAuthShareableKeyThrowsWithAuthCredentialsMissingCode(): void
    {
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');

        try {
            $this->handler->injectAuth($request, null, 'sk_test_key', null);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::AuthCredentialsMissing, $e->errorCode);
        }
    }

    #[Test]
    public function requestWithRetrySecond401ClearsTokenButPreservesCredentials(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('old-token', 3600);

        $this->mockClient->addResponse(new Response(401));
        $this->mockClient->addResponse(new Response(401));

        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123')
            ->withHeader('Authorization', 'Bearer old-token');

        try {
            $this->handler->requestWithRetry(
                $request,
                $this->mockClient,
                false,
                null,
                fn() => $this->tokenStore->setToken('new-token', 3600),
            );
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::AuthUnauthorized, $e->errorCode);
            // Token must be cleared
            $this->assertNull($this->tokenStore->getAccessToken());
            // Credentials must be preserved so the worker can re-authenticate
            $this->assertSame('client1', $this->tokenStore->getClientId());
        }
    }

    #[Test]
    public function injectAuthUsesPerRequestAccessTokenOverride(): void
    {
        $this->tokenStore->setToken('stored-token', 3600);
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $options = new RequestOptions(accessToken: 'override-token');

        $injected = $this->handler->injectAuth($request, $options, null, null);
        $this->assertSame('Bearer override-token', $injected->getHeaderLine('Authorization'));
    }

    #[Test]
    public function requestWithRetryProactivelyRefreshesExpiredToken(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('expiring-token', 0); // 0 s → already expired

        $this->mockClient->addResponse(new Response(200, [], '{}'));

        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $response = $this->handler->requestWithRetry(
            $request,
            $this->mockClient,
            false,
            null,
            fn() => $this->tokenStore->setToken('fresh-token', 3600),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fresh-token', $this->tokenStore->getAccessToken());
    }

    #[Test]
    public function requestWithRetrySkipsProactiveRefreshForPerRequestOverride(): void
    {
        // Token is expiring but request carries a per-request override — refresh must be skipped.
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('expiring-token', 0);

        $this->mockClient->addResponse(new Response(200, [], '{}'));

        $refreshCalled = false;
        $options = new RequestOptions(accessToken: 'override-token');
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123')
            ->withHeader('Authorization', 'Bearer override-token');

        $response = $this->handler->requestWithRetry(
            $request,
            $this->mockClient,
            false,
            $options,
            function () use (&$refreshCalled): void {
                $refreshCalled = true;
                $this->tokenStore->setToken('fresh-token', 3600);
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($refreshCalled, 'Proactive refresh must not run when per-request override is set');
        // The override token must not have been replaced in the outgoing request
        $sentRequests = $this->mockClient->getRequests();
        $this->assertSame('Bearer override-token', $sentRequests[0]->getHeaderLine('Authorization'));
    }

    #[Test]
    public function requestWithRetrySkips401RetryForPerRequestOverride(): void
    {
        // If a per-request override token gets a 401, the stored token is not the right
        // thing to refresh — return the 401 response directly.
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('stored-token', 3600);

        $this->mockClient->addResponse(new Response(401));

        $refreshCalled = false;
        $options = new RequestOptions(accessToken: 'override-token');
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123')
            ->withHeader('Authorization', 'Bearer override-token');

        $response = $this->handler->requestWithRetry(
            $request,
            $this->mockClient,
            false,
            $options,
            function () use (&$refreshCalled): void {
                $refreshCalled = true;
            },
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($refreshCalled, '401 retry must not run when per-request override is set');
    }
}

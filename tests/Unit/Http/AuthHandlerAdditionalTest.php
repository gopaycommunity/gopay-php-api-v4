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
        $this->handler = new AuthHandler(
            $this->tokenStore,
            'https://api.sandbox.gopay.com/api/merchant/payments/4.0',
            $this->factory,
            $this->factory,
        );
    }

    #[Test]
    public function injectAuthWithShareableKeyButNoClientIdThrows(): void
    {
        // No token set, shareableKey is set but clientId is null
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
    public function requestWithRetrySecond401ClearsTokenAndThrows(): void
    {
        // Set up credentials so the refresh flow runs
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('old-token', 3600);

        // First call: 401
        $this->mockClient->addResponse(new Response(401));
        // Token refresh: success
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":"new-token","expires_in":3600,"token_type":"bearer"}'));
        // Retry: 401 again
        $this->mockClient->addResponse(new Response(401));

        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123')
            ->withHeader('Authorization', 'Bearer old-token');

        try {
            $this->handler->requestWithRetry($request, $this->mockClient);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::AuthUnauthorized, $e->errorCode);
            // Token should be cleared after the second 401
            $this->assertNull($this->tokenStore->getAccessToken());
        }
    }

    #[Test]
    public function injectAuthUsesPerRequestAccessTokenOverride(): void
    {
        // Even if a token is stored, per-request token takes precedence
        $this->tokenStore->setToken('stored-token', 3600);
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $options = new RequestOptions(accessToken: 'override-token');

        $injected = $this->handler->injectAuth($request, $options, null, null);
        $this->assertSame('Bearer override-token', $injected->getHeaderLine('Authorization'));
    }

    #[Test]
    public function requestWithRetryProactivelyRefreshesExpiredToken(): void
    {
        // Set credentials first, then overlay an expiring token
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('expiring-token', 0); // 0 s → already expired

        // Token refresh response, then the actual API response
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":"fresh-token","expires_in":3600,"token_type":"bearer"}'));
        $this->mockClient->addResponse(new Response(200, [], '{}'));

        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $response = $this->handler->requestWithRetry($request, $this->mockClient);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fresh-token', $this->tokenStore->getAccessToken());
    }

    #[Test]
    public function refreshThrowsGoPaySdkExceptionOnNon2xxTokenResponse(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(500));

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('Token refresh failed: HTTP 500');
        $this->handler->refresh($this->mockClient);
    }

    #[Test]
    public function refreshThrowsGoPaySdkExceptionWhenResponseBodyIsNotJson(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(200, [], 'null')); // json_decode('null') → null, not array

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('Invalid token response: could not parse JSON');
        $this->handler->refresh($this->mockClient);
    }

    #[Test]
    public function refreshThrowsGoPaySdkExceptionWhenHttpClientThrows(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');

        $networkEx = new class ('Connection refused', $this->factory->createRequest('POST', 'https://example.com/oauth2/token')) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function __construct(string $message, private \Psr\Http\Message\RequestInterface $req)
            {
                parent::__construct($message);
            }

            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return $this->req;
            }
        };
        $this->mockClient->addException($networkEx);

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('Token refresh failed');
        $this->handler->refresh($this->mockClient);
    }
}

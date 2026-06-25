<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Http\AuthHandler;
use GoPay\Payments\Http\TokenStore;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\TestCase;

final class AuthHandlerTest extends TestCase
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

    public function testInjectAuthAddsBearerToken(): void
    {
        $this->tokenStore->setToken('my-token', 3600);
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $injected = $this->handler->injectAuth($request, null, null, null);
        $this->assertSame('Bearer my-token', $injected->getHeaderLine('Authorization'));
    }

    public function testInjectAuthSkipsTokenEndpoint(): void
    {
        $request = $this->factory->createRequest('POST', 'https://example.com/oauth2/token');
        // No token set; for the auth endpoint it should NOT throw.
        $injected = $this->handler->injectAuth($request, null, null, null);
        $this->assertFalse($injected->hasHeader('Authorization'));
    }

    public function testInjectAuthFallsBackToShareableKey(): void
    {
        // No bearer token; shareable key set.
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $injected = $this->handler->injectAuth($request, null, 'sharekey', 'client1');
        $expected = 'Basic ' . base64_encode('client1:sharekey');
        $this->assertSame($expected, $injected->getHeaderLine('Authorization'));
    }

    public function testInjectAuthThrowsWhenNoTokenAndNoShareableKey(): void
    {
        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123');
        $this->expectException(GoPaySdkException::class);
        $this->handler->injectAuth($request, null, null, null);
    }

    public function testRequestWithRetryRetries401(): void
    {
        $this->tokenStore->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->tokenStore->setToken('old-token', 3600);

        $this->mockClient->addResponse(new Response(401));
        $this->mockClient->addResponse(new Response(200, [], '{}'));

        $request = $this->factory->createRequest('GET', 'https://example.com/payments/123')
            ->withHeader('Authorization', 'Bearer old-token');

        $response = $this->handler->requestWithRetry(
            $request,
            $this->mockClient,
            false,
            null,
            fn() => $this->tokenStore->setToken('new-token', 3600),
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('new-token', $this->tokenStore->getAccessToken());

        $sentRequests = $this->mockClient->getRequests();
        $this->assertCount(2, $sentRequests);
        $this->assertSame('Bearer new-token', $sentRequests[1]->getHeaderLine('Authorization'));
    }
}

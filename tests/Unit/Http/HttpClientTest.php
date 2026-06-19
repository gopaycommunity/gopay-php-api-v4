<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Http\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    private MockClient $mockClient;
    private HttpFactory $factory;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new HttpFactory();
        $this->http = new HttpClient(
            config: new Config(environment: Environment::Sandbox, baseUrl: 'https://mock.example.com'),
            client: $this->mockClient,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        // Pre-authenticate so requests have a bearer token.
        $this->http->getTokenStore()->setToken('test-token', 3600);
    }

    public function testDeleteSends204WithNoBody(): void
    {
        $this->mockClient->addResponse(new Response(204));
        $this->http->delete('/cards/tokens/abc123');
        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/cards/tokens/abc123', (string) $requests[0]->getUri());
        $this->assertSame('Bearer test-token', $requests[0]->getHeaderLine('Authorization'));
    }

    public function testThrowsGoPayHttpExceptionOn4xx(): void
    {
        $this->mockClient->addResponse(new Response(422, [], '{"error":"invalid"}'));
        $this->expectException(GoPayHttpException::class);
        $this->expectExceptionMessage('HTTP 422');
        $this->http->delete('/some/path');
    }

    public function testThrowsGoPaySdkExceptionWhenNoToken(): void
    {
        $http = new HttpClient(
            config: new Config(environment: Environment::Sandbox, baseUrl: 'https://mock.example.com'),
            client: $this->mockClient,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $this->expectException(GoPaySdkException::class);
        $http->delete('/some/path');
    }

    public function testOnErrorCallbackFires(): void
    {
        $caught = null;
        $http = new HttpClient(
            config: new Config(
                environment: Environment::Sandbox,
                baseUrl: 'https://mock.example.com',
                onError: function (\Throwable $e) use (&$caught): void {
                    $caught = $e;
                },
            ),
            client: $this->mockClient,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $http->getTokenStore()->setToken('test-token', 3600);
        $this->mockClient->addResponse(new Response(401, [], '{"error":"unauthorized"}'));
        // No client credentials → no refresh → propagates as 401 HTTP error.
        $this->mockClient->addResponse(new Response(401, [], '{"error":"unauthorized"}'));
        try {
            $http->delete('/some/path');
        } catch (GoPayHttpException) {
        } catch (GoPaySdkException) {
        }
        $this->assertNotNull($caught);
    }

    public function testGetArrayReturnsDecodedBody(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"foo":"bar"}'));
        $result = $this->http->getArray('/some/path');
        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function testDebugLoggingDoesNotThrow(): void
    {
        $http = new HttpClient(
            config: new Config(
                environment: Environment::Sandbox,
                baseUrl: 'https://mock.example.com',
                debugLoggingEnabled: true,
            ),
            client: $this->mockClient,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $http->getTokenStore()->setToken('test-token', 3600);
        $this->mockClient->addResponse(new Response(204));
        $http->delete('/test');
        $this->addToAssertionCount(1);
    }
}

<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Http\RequestOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpClientAdditionalTest extends TestCase
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
        $this->http->getTokenStore()->setToken('test-token', 3600);
    }

    #[Test]
    public function getReturnsDeserializedModel(): void
    {
        $body = json_encode([
            'id' => 'pay-001',
            'order_number' => 'ORDER-001',
            'state' => 'CREATED',
            'amount' => 1000,
            'currency' => 'CZK',
            'customer' => ['email' => 'test@example.com'],
            'gw_url' => 'https://gw.example.com',
            'payment_secret' => 'secret',
        ], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $body));

        $result = $this->http->get('/payments/pay-001', PaymentDetails::class);

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('pay-001', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001', (string) $requests[0]->getUri());
    }

    #[Test]
    public function postReturnsDeserializedModel(): void
    {
        $body = json_encode([
            'id' => 'charge-001',
            'state' => 'PROCESSING',
        ], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $body));

        $result = $this->http->post(
            '/payments/pay-001/charge',
            ['foo' => 'bar'],
            PaymentChargeResponse::class,
        );

        $this->assertInstanceOf(PaymentChargeResponse::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/charge', (string) $requests[0]->getUri());
        $this->assertSame('application/json', $requests[0]->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function postArrayReturnsDecodedArray(): void
    {
        $responseBody = json_encode(['token' => 'card-token-xyz', 'card_id' => 'card-001'], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $responseBody));

        $result = $this->http->postArray('/some/path', ['key' => 'val']);

        $this->assertArrayHasKey('token', $result);
        $this->assertSame('card-token-xyz', $result['token']);
    }

    #[Test]
    public function requestOptionsExtraHeadersAreAttached(): void
    {
        $this->mockClient->addResponse(new Response(204));

        $options = new RequestOptions(headers: ['X-Custom' => 'my-value']);
        $this->http->delete('/some/path', $options);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('my-value', $requests[0]->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function requestOptionsAccessTokenOverridesStoredToken(): void
    {
        $this->mockClient->addResponse(new Response(204));

        $options = new RequestOptions(accessToken: 'override-token');
        $this->http->delete('/some/path', $options);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('Bearer override-token', $requests[0]->getHeaderLine('Authorization'));
    }

    #[Test]
    public function postFormSendsFormEncodedBodyAndReturnsModel(): void
    {
        $body = json_encode(['id' => 'pay-001', 'state' => 'CREATED'], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $body));

        $result = $this->http->postForm(
            '/eshops/goid/payments',
            ['grant_type' => 'client_credentials', 'scope' => 'payment:read'],
            \GoPay\Payments\Generated\Model\PaymentDetails::class,
        );

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertSame('application/x-www-form-urlencoded', $requests[0]->getHeaderLine('Content-Type'));
        $this->assertInstanceOf(\GoPay\Payments\Generated\Model\PaymentDetails::class, $result);
    }

    #[Test]
    public function getArrayThrowsUnexpectedResponseOnNonObjectResponse(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '[1,2,3]'));

        try {
            $this->http->getArray('/payments/pay-001/google-pay/info');
            $this->fail('Expected GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        }
    }

    #[Test]
    public function getThrowsUnexpectedResponseOnInvalidJsonBody(): void
    {
        $this->mockClient->addResponse(new Response(200, [], 'not-valid-json'));

        try {
            $this->http->get('/payments/pay-001', PaymentDetails::class);
            $this->fail('Expected GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        }
    }

    #[Test]
    public function sendThrowsGoPaySdkExceptionOnNetworkException(): void
    {
        $networkEx = new class ('Network down', $this->factory->createRequest('GET', '/')) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
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

        $this->expectException(\GoPay\Payments\Exception\GoPaySdkException::class);
        $this->expectExceptionMessage('Network error');
        $this->http->delete('/some/path');
    }

    #[Test]
    public function sendThrowsGoPaySdkExceptionOnClientException(): void
    {
        // ClientExceptionInterface but NOT NetworkExceptionInterface → falls into the second catch
        $clientEx = new class ('Client error') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        $this->mockClient->addException($clientEx);

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('HTTP client error');
        $this->http->delete('/some/path');
    }

    // -------------------------------------------------------------------------
    // refresh() — moved from AuthHandler
    // -------------------------------------------------------------------------

    #[Test]
    public function refreshStoresNewToken(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":"new-token","expires_in":3600,"token_type":"bearer"}'));
        $this->http->refresh();
        $this->assertSame('new-token', $this->http->getTokenStore()->getAccessToken());
    }

    #[Test]
    public function refreshThrowsWhenNoCredentials(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->http->refresh();
    }

    #[Test]
    public function refreshThrowsOnInvalidResponse(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":""}'));
        $this->expectException(GoPaySdkException::class);
        $this->http->refresh();
    }

    #[Test]
    public function refreshThrowsOnNon2xxTokenResponse(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(500));

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('Token refresh failed: HTTP 500');
        $this->http->refresh();
    }

    #[Test]
    public function refreshThrowsWhenResponseBodyIsNotJson(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(200, [], 'null'));

        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('Invalid token response: could not parse JSON');
        $this->http->refresh();
    }

    #[Test]
    public function refreshThrowsWhenNetworkExceptionOccurs(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');

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
        $this->http->refresh();
    }

    #[Test]
    public function refreshPreservesCredentialsOnTransientError(): void
    {
        $this->http->getTokenStore()->setClientCredentials('client1', 'secret1', 'payment:read');
        $this->mockClient->addResponse(new Response(500));

        try {
            $this->http->refresh();
        } catch (GoPaySdkException) {
        }

        // Token must be cleared, but credentials must survive so the worker can re-authenticate.
        $this->assertNull($this->http->getTokenStore()->getAccessToken());
        $this->assertSame('client1', $this->http->getTokenStore()->getClientId());
    }

    // -------------------------------------------------------------------------
    // shareableKey lives in TokenStore
    // -------------------------------------------------------------------------

    #[Test]
    public function setShareableKeyUpdatesTokenStore(): void
    {
        $this->http->setShareableKey('sk_updated');
        $this->assertSame('sk_updated', $this->http->getShareableKey());
        $this->assertSame('sk_updated', $this->http->getTokenStore()->getShareableKey());
    }

    #[Test]
    public function getJsonListReturnsArray(): void
    {
        $body = json_encode([
            ['id' => 'ref-001', 'state' => 'SUCCESS', 'amount' => 500, 'currency' => 'CZK'],
            ['id' => 'ref-002', 'state' => 'SUCCESS', 'amount' => 200, 'currency' => 'CZK'],
        ], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $body));

        $result = $this->http->getJsonList('/payments/pay-001/refunds');

        $this->assertCount(2, $result);
        $this->assertSame('ref-001', $result[0]['id']);
        $this->assertSame('ref-002', $result[1]['id']);
    }

    #[Test]
    public function getJsonListWithSingleItemReturnsCountOne(): void
    {
        $body = json_encode([
            ['id' => 'ref-001', 'state' => 'SUCCESS'],
        ], JSON_THROW_ON_ERROR);
        $this->mockClient->addResponse(new Response(200, [], $body));

        $result = $this->http->getJsonList('/payments/pay-001/refunds');

        $this->assertCount(1, $result);
        $this->assertSame('ref-001', $result[0]['id']);
    }

    #[Test]
    public function getJsonListReturnsEmptyArrayForPaymentWithNoRefunds(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '[]'));

        $this->assertSame([], $this->http->getJsonList('/payments/pay-001/refunds'));
    }

    #[Test]
    public function getJsonListThrowsUnexpectedResponseOnScalarItems(): void
    {
        // A list of scalars would otherwise reach callers that map over the items
        // as arrays, surfacing as an uncatchable TypeError instead of an SDK error.
        $this->mockClient->addResponse(new Response(200, [], '["ref-001","ref-002"]'));

        try {
            $this->http->getJsonList('/payments/pay-001/refunds');
            $this->fail('Expected GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        }
    }

    #[Test]
    public function getJsonListThrowsUnexpectedResponseOnNullItems(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '[null]'));

        try {
            $this->http->getJsonList('/payments/pay-001/refunds');
            $this->fail('Expected GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        }
    }

    #[Test]
    public function getJsonListThrowsUnexpectedResponseOnNonListResponse(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{"error":"unexpected"}'));

        try {
            $this->http->getJsonList('/payments/pay-001/refunds');
            $this->fail('Expected GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        }
    }
}

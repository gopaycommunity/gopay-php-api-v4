<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Http\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\TestCase;

abstract class ModuleTestCase extends TestCase
{
    protected MockClient $mockClient;
    protected HttpFactory $factory;
    protected HttpClient $http;

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

    /**
     * Queue a successful JSON response.
     */
    protected function queueJson(mixed $data, int $status = 200): void
    {
        $this->mockClient->addResponse(
            new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Queue a 204 No Content response.
     */
    protected function queue204(): void
    {
        $this->mockClient->addResponse(new Response(204));
    }

    /**
     * Queue a token refresh response, then optionally more responses.
     */
    protected function queueTokenRefreshResponse(): void
    {
        $this->mockClient->addResponse(
            new Response(200, [], '{"access_token":"refreshed-token","expires_in":3600,"token_type":"bearer"}'),
        );
    }

    /**
     * Return a minimal PaymentDetails-compatible JSON structure.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function paymentDetailsJson(array $overrides = []): array
    {
        return array_merge([
            'id' => 'pay-001',
            'order_number' => 'ORDER-001',
            'state' => 'CREATED',
            'amount' => 1000,
            'currency' => 'CZK',
            'customer' => ['email' => 'test@example.com'],
            'gw_url' => 'https://gw.example.com',
            'payment_secret' => 'secret',
        ], $overrides);
    }

    /**
     * Return a minimal PaymentChargeResponse JSON structure.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function chargeResponseJson(array $overrides = []): array
    {
        return array_merge([
            'id' => 'charge-001',
            'state' => 'PROCESSING',
        ], $overrides);
    }

    /**
     * Return a minimal PaymentChargeStatusResponse JSON structure.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function chargeStatusJson(array $overrides = []): array
    {
        return array_merge([
            'id' => 'charge-001',
            'state' => 'PROCESSING',
        ], $overrides);
    }
}

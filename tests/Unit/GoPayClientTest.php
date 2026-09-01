<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\LinkStopReason;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\GoPayClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GoPayClientTest extends TestCase
{
    private MockClient $mockClient;
    private GoPayClient $sdk;

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $factory = new HttpFactory();
        $this->sdk = new GoPayClient(
            new Config(environment: Environment::Sandbox, baseUrl: 'https://mock.example.com'),
            $this->mockClient,
            $factory,
            $factory,
        );
        // Authenticate so all subsequent calls have a valid token.
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":"test-tok","expires_in":3600,"token_type":"bearer"}'));
        $this->sdk->authenticate('client_id', 'client_secret', 'payment:write payment:read card:write card:read');
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    #[Test]
    public function isAuthenticatedReturnsTrueAfterAuthenticate(): void
    {
        $this->assertTrue($this->sdk->isAuthenticated());
    }

    #[Test]
    public function logoutClearsAuthentication(): void
    {
        $this->sdk->logout();
        $this->assertFalse($this->sdk->isAuthenticated());
    }

    #[Test]
    public function setShareableKeyAndGetBrowserKeys(): void
    {
        $this->sdk->setShareableKey('sk_test');
        $keys = $this->sdk->getBrowserKeys();
        $this->assertSame('sk_test', $keys['shareable_key']);
        $this->assertSame('client_id', $keys['client_id']);
    }

    // ── Payments ─────────────────────────────────────────────────────────────

    #[Test]
    public function createPaymentDelegates(): void
    {
        $this->queueJson(['id' => 'pay-001', 'state' => 'CREATED']);
        $result = $this->sdk->createPayment('goid-123', ['amount' => 1990, 'currency' => 'CZK']);
        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('pay-001', $result->getId());
    }

    #[Test]
    public function getPaymentStatusDelegates(): void
    {
        $this->queueJson(['id' => 'pay-001', 'state' => 'PAID']);
        $result = $this->sdk->getPaymentStatus('pay-001');
        $this->assertInstanceOf(PaymentDetails::class, $result);
    }

    #[Test]
    public function chargePaymentDelegates(): void
    {
        $this->queueJson(['id' => 'chg-001', 'state' => 'REQUESTED']);
        $result = $this->sdk->chargePayment('pay-001', ['payment_instrument' => []]);
        $this->assertInstanceOf(PaymentChargeResponse::class, $result);
    }

    #[Test]
    public function getChargeStateDelegates(): void
    {
        $this->queueJson(['id' => 'chg-001', 'state' => 'SUCCEEDED']);
        $result = $this->sdk->getChargeState('pay-001');
        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $result);
    }

    #[Test]
    public function getGooglePayInfoDelegates(): void
    {
        $this->queueJson(['paymentRequest' => ['merchantInfo' => []]]);
        $result = $this->sdk->getGooglePayInfo('pay-001');
        $this->assertArrayHasKey('paymentRequest', $result);
    }

    #[Test]
    public function getApplePayInfoDelegates(): void
    {
        $this->queueJson(['applePayPaymentRequest' => []]);
        $result = $this->sdk->getApplePayInfo('pay-001');
        $this->assertArrayHasKey('applePayPaymentRequest', $result);
    }

    #[Test]
    public function validateApplePayMerchantDelegates(): void
    {
        $this->queueJson(['merchantSession' => 'session-data']);
        $result = $this->sdk->validateApplePayMerchant('pay-001', ['validationUrl' => 'https://apple.com/session']);
        $this->assertArrayHasKey('merchantSession', $result);
    }

    #[Test]
    public function getQrPaymentInfoDelegates(): void
    {
        $this->queueJson(['amount' => 1990, 'currency' => 'CZK']);
        $result = $this->sdk->getQrPaymentInfo('pay-001');
        $this->assertInstanceOf(QRPaymentDetails::class, $result);
    }

    #[Test]
    public function awaitChargeStateDelegatesOnSuccess(): void
    {
        $this->queueJson(['id' => 'chg-001', 'state' => 'SUCCEEDED']);
        $result = $this->sdk->awaitChargeState('pay-001', timeoutSeconds: 30, pollIntervalMs: 1);
        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $result);
        $this->assertSame('SUCCEEDED', $result->getState());
    }

    // ── Cards ─────────────────────────────────────────────────────────────────

    #[Test]
    public function getCardDetailsDelegates(): void
    {
        $this->queueJson(['card_id' => 'card-001', 'token' => 'tok-001', 'status' => 'ACTIVE', 'expiration_year' => '27']);
        $result = $this->sdk->getCardDetails('card-001');
        $this->assertInstanceOf(PermanentCardTokenDetails::class, $result);
    }

    #[Test]
    public function deleteCardDelegates(): void
    {
        $this->mockClient->addResponse(new Response(204));
        $this->sdk->deleteCard('card-001');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function tokenizeEncryptedCardDelegates(): void
    {
        $this->queueJson(['card_id' => 'card-001', 'token' => 'tok-001', 'status' => 'ACTIVE', 'expiration_year' => '27']);
        $result = $this->sdk->tokenizeEncryptedCard('jwe-payload');
        $this->assertInstanceOf(PermanentCardTokenDetails::class, $result);
    }

    // ── Refunds ───────────────────────────────────────────────────────────────

    #[Test]
    public function refundPaymentDelegates(): void
    {
        $this->queueJson(['id' => 'ref-001', 'state' => 'REQUESTED', 'amount' => 500, 'currency' => 'CZK']);
        $result = $this->sdk->refundPayment('pay-001', ['amount' => 500]);
        $this->assertInstanceOf(RefundDetails::class, $result);
    }

    #[Test]
    public function listRefundsDelegates(): void
    {
        $this->mockClient->addResponse(new Response(
            200,
            [],
            json_encode([['id' => 'ref-001', 'state' => 'REQUESTED', 'amount' => 500, 'currency' => 'CZK']], JSON_THROW_ON_ERROR),
        ));
        $results = $this->sdk->listRefunds('pay-001');
        $this->assertCount(1, $results);
        $this->assertInstanceOf(RefundDetails::class, $results[0]);
    }

    #[Test]
    public function getRefundDelegates(): void
    {
        $this->queueJson(['id' => 'ref-001', 'state' => 'SUCCESS', 'amount' => 500, 'currency' => 'CZK']);
        $result = $this->sdk->getRefund('ref-001');
        $this->assertInstanceOf(RefundDetails::class, $result);
    }

    #[Test]
    public function awaitRefundStateDelegates(): void
    {
        $this->queueJson(['id' => 'ref-001', 'state' => 'SUCCESS', 'amount' => 500, 'currency' => 'CZK']);
        $result = $this->sdk->awaitRefundState('ref-001', timeoutSeconds: 30, pollIntervalMs: 1);
        $this->assertInstanceOf(RefundDetails::class, $result);
        $this->assertSame('SUCCESS', $result->getState());
    }

    // ── Payment links ─────────────────────────────────────────────────────────

    #[Test]
    public function createPaymentLinkDelegates(): void
    {
        $this->queueJson([
            'id' => '3405871122',
            'url' => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active' => true,
            'reusable' => true,
        ], 201);

        $result = $this->sdk->createPaymentLink('8398119642', [
            'payment' => [
                'amount' => 15000,
                'currency' => 'CZK',
                'order_number' => '2026-00042',
                'customer' => ['email' => 'payer@example.com'],
                'callback' => [
                    'notification_url' => 'https://eshop.example.com/gopay/notify',
                    'return_url' => 'https://eshop.example.com/gopay/return',
                ],
            ],
        ]);

        $this->assertInstanceOf(LinkDetails::class, $result);
        $this->assertSame('3405871122', $result->getId());

        $request = $this->mockClient->getRequests()[1];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links', (string) $request->getUri());
    }

    #[Test]
    public function linkStatusDelegates(): void
    {
        $this->queueJson([
            'id' => '3405871122',
            'url' => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active' => false,
            'reusable' => false,
            'stop_reason' => 'USED',
        ]);

        $result = $this->sdk->linkStatus('8398119642', '3405871122');

        $this->assertInstanceOf(LinkDetails::class, $result);
        $this->assertSame(LinkStopReason::USED, $result->getStopReason());

        $request = $this->mockClient->getRequests()[1];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links/3405871122', (string) $request->getUri());
    }

    #[Test]
    public function disableLinkDelegates(): void
    {
        $this->mockClient->addResponse(new Response(204));

        $this->sdk->disableLink('8398119642', '3405871122');

        $request = $this->mockClient->getRequests()[1];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links/3405871122', (string) $request->getUri());
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /** @param array<mixed> $data */
    private function queueJson(array $data, int $status = 200): void
    {
        $this->mockClient->addResponse(
            new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR)),
        );
    }

}

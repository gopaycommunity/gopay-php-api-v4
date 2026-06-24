<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Module\PaymentsApi;
use PHPUnit\Framework\Attributes\Test;

final class PaymentsApiTest extends ModuleTestCase
{
    private PaymentsApi $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = new PaymentsApi($this->http);
    }

    // -------------------------------------------------------------------------
    // createPayment
    // -------------------------------------------------------------------------

    #[Test]
    public function createPaymentThrowsWhenGoidIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('goid must not be empty');
        $this->payments->createPayment('', []);
    }

    #[Test]
    public function createPaymentReturnsPaymentDetails(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => 'pay-001']));

        $result = $this->payments->createPayment('goid-123', ['amount' => 1000, 'currency' => 'CZK']);

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('pay-001', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringContainsString('/eshops/goid-123/payments', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // getPaymentStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function getPaymentStatusThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->getPaymentStatus('');
    }

    #[Test]
    public function getPaymentStatusReturnsPaymentDetails(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => 'pay-123', 'state' => 'CREATED']));

        $result = $this->payments->getPaymentStatus('pay-123');

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('pay-123', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-123', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // chargePayment
    // -------------------------------------------------------------------------

    #[Test]
    public function chargePaymentThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->chargePayment('', []);
    }

    #[Test]
    public function chargePaymentReturnsPaymentChargeResponse(): void
    {
        $this->queueJson($this->chargeResponseJson(['id' => 'charge-001']));

        $result = $this->payments->chargePayment('pay-001', ['payment_instrument' => []]);

        $this->assertInstanceOf(PaymentChargeResponse::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/charge', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // getChargeState
    // -------------------------------------------------------------------------

    #[Test]
    public function getChargeStateThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->getChargeState('');
    }

    #[Test]
    public function getChargeStateReturnsPaymentChargeStatusResponse(): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        $result = $this->payments->getChargeState('pay-001');

        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/charge', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // getGooglePayInfo
    // -------------------------------------------------------------------------

    #[Test]
    public function getGooglePayInfoThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->getGooglePayInfo('');
    }

    #[Test]
    public function getGooglePayInfoReturnsArray(): void
    {
        $this->queueJson(['apiVersion' => 2, 'apiVersionMinor' => 0, 'allowedPaymentMethods' => []]);

        $result = $this->payments->getGooglePayInfo('pay-001');

        $this->assertArrayHasKey('apiVersion', $result);
        $this->assertSame(2, $result['apiVersion']);

        $requests = $this->mockClient->getRequests();
        $this->assertStringEndsWith('/payments/pay-001/google-pay/info', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // getApplePayInfo
    // -------------------------------------------------------------------------

    #[Test]
    public function getApplePayInfoThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->getApplePayInfo('');
    }

    #[Test]
    public function getApplePayInfoReturnsArray(): void
    {
        $this->queueJson(['applepayVersion' => 3, 'applePayPaymentRequest' => []]);

        $result = $this->payments->getApplePayInfo('pay-001');

        $this->assertArrayHasKey('applepayVersion', $result);
        $this->assertSame(3, $result['applepayVersion']);

        $requests = $this->mockClient->getRequests();
        $this->assertStringEndsWith('/payments/pay-001/apple-pay/info', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // validateApplePayMerchant
    // -------------------------------------------------------------------------

    #[Test]
    public function validateApplePayMerchantThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->validateApplePayMerchant('', null, null);
    }

    #[Test]
    public function validateApplePayMerchantReturnsArray(): void
    {
        $this->queueJson(['merchantIdentifier' => 'merchant.com.example', 'domainName' => 'example.com']);

        $result = $this->payments->validateApplePayMerchant('pay-001', ['validationUrl' => 'https://apple.com/validate']);

        $this->assertArrayHasKey('merchantIdentifier', $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/apple-pay/validate', (string) $requests[0]->getUri());
    }

    #[Test]
    public function validateApplePayMerchantWithOriginAddsOriginHeader(): void
    {
        $this->queueJson(['merchantIdentifier' => 'merchant.com.example']);

        $this->payments->validateApplePayMerchant(
            'pay-001',
            ['validationUrl' => 'https://apple.com/validate'],
            'https://myshop.example.com',
        );

        $requests = $this->mockClient->getRequests();
        $this->assertSame('https://myshop.example.com', $requests[0]->getHeaderLine('Origin'));
    }

    // -------------------------------------------------------------------------
    // getQrPaymentInfo
    // -------------------------------------------------------------------------

    #[Test]
    public function getQrPaymentInfoThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->getQrPaymentInfo('');
    }

    #[Test]
    public function getQrPaymentInfoReturnsQrPaymentDetails(): void
    {
        $this->queueJson(['qr_codes' => [], 'recipient' => []]);

        $result = $this->payments->getQrPaymentInfo('pay-001');

        $this->assertInstanceOf(QRPaymentDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertStringEndsWith('/payments/pay-001/qr-payment/info', (string) $requests[0]->getUri());
    }

    #[Test]
    public function getQrPaymentInfoWithFormatAppendsSvgQueryParam(): void
    {
        $this->queueJson(['qr_codes' => [], 'recipient' => []]);

        $this->payments->getQrPaymentInfo('pay-001', 'svg');

        $requests = $this->mockClient->getRequests();
        $this->assertStringContainsString('?format=svg', (string) $requests[0]->getUri());
    }

    #[Test]
    public function getQrPaymentInfoThrowsOnInvalidFormat(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('format must be "png" or "svg"');
        $this->payments->getQrPaymentInfo('pay-001', 'gif');
    }

    // -------------------------------------------------------------------------
    // awaitChargeState
    // -------------------------------------------------------------------------

    #[Test]
    public function awaitChargeStateThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->awaitChargeState('');
    }

    #[Test]
    public function awaitChargeStateThrowsWhenTimeoutIsZero(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('timeoutSeconds must be > 0');
        $this->payments->awaitChargeState('pay-001', 0);
    }

    #[Test]
    public function awaitChargeStateThrowsWhenTimeoutIsNegative(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('timeoutSeconds must be > 0');
        $this->payments->awaitChargeState('pay-001', -1);
    }

    #[Test]
    public function awaitChargeStateThrowsWhenPollIntervalIsZero(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('pollIntervalMs must be > 0');
        $this->payments->awaitChargeState('pay-001', 30, 0);
    }

    #[Test]
    public function awaitChargeStateThrowsWhenPollIntervalIsNegative(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('pollIntervalMs must be > 0');
        $this->payments->awaitChargeState('pay-001', 30, -100);
    }

    #[Test]
    public function awaitChargeStateReturnsOnSucceeded(): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        $result = $this->payments->awaitChargeState('pay-001', 30, 100);

        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $result);
        $this->assertSame('SUCCEEDED', $result->getState());
    }

    #[Test]
    public function awaitChargeStateThrowsOnFailed(): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'FAILED']));

        try {
            $this->payments->awaitChargeState('pay-001', 30, 100);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::ChargeFailed, $e->errorCode);
        }
    }

    #[Test]
    public function awaitChargeStateThrowsOnCancelled(): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'CANCELLED']));

        try {
            $this->payments->awaitChargeState('pay-001', 30, 100);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::ChargeFailed, $e->errorCode);
        }
    }

    #[Test]
    public function awaitChargeStateThrowsOnTimeout(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->queueJson($this->chargeStatusJson(['state' => 'PROCESSING']));
        }

        try {
            $this->payments->awaitChargeState('pay-001', 1, 100);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::ChargeTimeout, $e->errorCode);
        }
    }
}

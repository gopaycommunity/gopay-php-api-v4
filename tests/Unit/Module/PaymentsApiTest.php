<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\ChargeState;
use GoPay\Payments\Generated\Model\Emv3DSState;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Module\PaymentsApi;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // cancelPayment
    // -------------------------------------------------------------------------

    #[Test]
    public function cancelPaymentThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->payments->cancelPayment('');
    }

    #[Test]
    public function cancelPaymentSendsDeleteRequest(): void
    {
        $this->queue204();

        $this->payments->cancelPayment('pay-123');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-123', (string) $requests[0]->getUri());
    }

    #[Test]
    public function cancelPaymentCompletesWithoutThrowingOnSuccess(): void
    {
        $this->queue204();
        $this->payments->cancelPayment('pay-abc');
        $this->addToAssertionCount(1);
    }

    /**
     * Only a payment in CREATED can be canceled. Any other state answers 409 and
     * leaves the payment unchanged, so a second cancel of the same payment fails.
     */
    #[Test]
    public function cancelPaymentSurfacesConflictOutsideCreated(): void
    {
        $this->queueJson(
            ['error' => 'CONFLICT', 'message' => 'Payment can only be canceled in the CREATED state'],
            409,
        );

        try {
            $this->payments->cancelPayment('pay-123');
            $this->fail('Expected GoPayHttpException for a payment outside CREATED.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(409, $e->status);
        }
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
    public function awaitChargeStateReturnsOnActionRequiredWithoutWaitingForTimeout(): void
    {
        // A charge with a 3DS challenge usually answers POST with PROCESSING and
        // only turns ACTION_REQUIRED on a later poll. The SUCCEEDED queued after it
        // stands in for "kept polling": a poller that ignores ACTION_REQUIRED
        // returns that instead of the redirect.
        $this->queueJson($this->chargeStatusJson(['state' => 'PROCESSING']));
        $this->queueJson($this->chargeStatusJson([
            'state' => 'ACTION_REQUIRED',
            'action' => $this->challengeActionJson(),
        ]));
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        $result = $this->payments->awaitChargeState('pay-001', 30, 1);

        $this->assertSame('ACTION_REQUIRED', $result->getState());
        $this->assertSame('https://gate.example/payments/pay-001/redirect', $result->getAction()?->getRedirectUrl());
        $this->assertCount(2, $this->mockClient->getRequests());
    }

    /**
     * What awaitChargeState() does on ACTION_REQUIRED for every 3DS action state
     * the spec declares: return while the customer still has the step ahead of
     * them, keep polling once it is over (the return-URL case). A state the spec
     * adds later fails {@see awaitChargeStateOnActionRequiredHasAnOutcomeForEvery3dsState}
     * until someone decides which side it is on.
     *
     * @return array<string, 'return'|'poll'>
     */
    private static function actionStateOutcomes(): array
    {
        return [
            Emv3DSState::CREATED                    => 'return',
            Emv3DSState::CHALLENGE_REQUIRED         => 'return',
            Emv3DSState::AUTHENTICATED_CHALLENGE    => 'poll',
            Emv3DSState::AUTHENTICATED_FRICTIONLESS => 'poll',
            Emv3DSState::NOT_AUTHENTICATED          => 'poll',
            Emv3DSState::FAILED                     => 'poll',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actionStates(): iterable
    {
        foreach (Emv3DSState::getAllowableEnumValues() as $state) {
            yield $state => [$state];
        }
    }

    #[Test]
    #[DataProvider('actionStates')]
    public function awaitChargeStateOnActionRequiredHasAnOutcomeForEvery3dsState(string $actionState): void
    {
        $outcomes = self::actionStateOutcomes();
        $this->assertArrayHasKey(
            $actionState,
            $outcomes,
            "Emv3DSState {$actionState} is new: decide whether awaitChargeState() returns or keeps polling on it.",
        );

        $this->queueJson($this->chargeStatusJson([
            'state' => 'ACTION_REQUIRED',
            'action' => ['state' => $actionState] + $this->challengeActionJson(),
        ]));
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        $result = $this->payments->awaitChargeState('pay-001', 30, 1);

        if ($outcomes[$actionState] === 'return') {
            $this->assertSame('ACTION_REQUIRED', $result->getState());
            $this->assertCount(1, $this->mockClient->getRequests());
        } else {
            $this->assertSame('SUCCEEDED', $result->getState());
            $this->assertCount(2, $this->mockClient->getRequests());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function actionsWithoutRedirectUrl(): iterable
    {
        yield 'no action at all'       => [null];
        yield 'action without the URL' => [['action_type' => 'EMV3DS', 'state' => 'CREATED']];
        yield 'action with empty URL'  => [['action_type' => 'EMV3DS', 'state' => 'CHALLENGE_REQUIRED', 'redirect_url' => '']];
    }

    /**
     * @param array<string, mixed>|null $action
     */
    #[Test]
    #[DataProvider('actionsWithoutRedirectUrl')]
    public function awaitChargeStateKeepsPollingOnActionRequiredWithoutRedirectUrl(?array $action): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'ACTION_REQUIRED', 'action' => $action]));
        $this->queueJson($this->chargeStatusJson([
            'state' => 'ACTION_REQUIRED',
            'action' => $this->challengeActionJson(),
        ]));

        $result = $this->payments->awaitChargeState('pay-001', 30, 1);

        $this->assertSame('https://gate.example/payments/pay-001/redirect', $result->getAction()?->getRedirectUrl());
        $this->assertCount(2, $this->mockClient->getRequests());
    }

    /**
     * Every state the generated ChargeState declares, with what awaitChargeState()
     * does on it. The ACTION_REQUIRED bug went unnoticed because only SUCCEEDED,
     * FAILED and PROCESSING were ever exercised — this pins the rest, and
     * {@see awaitChargeStateHasAnOutcomeForEveryChargeState} fails when the spec
     * adds a state nobody has decided on.
     *
     * Outcome: 'return' (on the first poll), 'throw' (CHARGE_FAILED) or 'poll'
     * (keeps going; the test queues a SUCCEEDED after it to end the loop).
     *
     * @return array<string, 'return'|'throw'|'poll'>
     */
    private static function chargeStateOutcomes(): array
    {
        return [
            ChargeState::REQUESTED       => 'poll',
            ChargeState::PROCESSING      => 'poll',
            ChargeState::ACTION_REQUIRED => 'return',
            ChargeState::SUCCEEDED       => 'return',
            ChargeState::FAILED          => 'throw',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chargeStates(): iterable
    {
        foreach (ChargeState::getAllowableEnumValues() as $state) {
            yield $state => [$state];
        }
    }

    #[Test]
    #[DataProvider('chargeStates')]
    public function awaitChargeStateHasAnOutcomeForEveryChargeState(string $state): void
    {
        $outcomes = self::chargeStateOutcomes();
        $this->assertArrayHasKey(
            $state,
            $outcomes,
            "ChargeState {$state} is new: decide whether awaitChargeState() returns, throws or keeps polling on it.",
        );

        $this->queueJson($this->chargeStatusJson([
            'state' => $state,
            'action' => $state === ChargeState::ACTION_REQUIRED ? $this->challengeActionJson() : null,
        ]));
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        try {
            $result = $this->payments->awaitChargeState('pay-001', 30, 1);
        } catch (GoPaySdkException $e) {
            $this->assertSame('throw', $outcomes[$state], "{$state} threw {$e->errorCode->value}.");
            $this->assertSame(ErrorCode::ChargeFailed, $e->errorCode);
            $this->assertCount(1, $this->mockClient->getRequests());

            return;
        }

        $this->assertNotSame('throw', $outcomes[$state], "{$state} should have thrown.");
        if ($outcomes[$state] === 'return') {
            $this->assertSame($state, $result->getState());
            $this->assertCount(1, $this->mockClient->getRequests());
        } else {
            $this->assertSame('SUCCEEDED', $result->getState());
            $this->assertCount(2, $this->mockClient->getRequests());
        }
    }

    #[Test]
    public function awaitChargeStatePollsThroughRequestedAndProcessing(): void
    {
        $this->queueJson($this->chargeStatusJson(['state' => 'REQUESTED']));
        $this->queueJson($this->chargeStatusJson(['state' => 'PROCESSING']));
        $this->queueJson($this->chargeStatusJson(['state' => 'PROCESSING']));
        $this->queueJson($this->chargeStatusJson(['state' => 'SUCCEEDED']));

        $result = $this->payments->awaitChargeState('pay-001', 30, 1);

        $this->assertSame('SUCCEEDED', $result->getState());
        $requests = $this->mockClient->getRequests();
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertStringEndsWith('/payments/pay-001/charge', $request->getUri()->getPath());
        }
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

    /**
     * @return array<string, string>
     */
    private function challengeActionJson(): array
    {
        return [
            'action_type' => 'EMV3DS',
            'state' => 'CHALLENGE_REQUIRED',
            'redirect_url' => 'https://gate.example/payments/pay-001/redirect',
        ];
    }
}

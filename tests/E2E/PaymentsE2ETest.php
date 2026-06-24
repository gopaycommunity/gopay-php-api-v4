<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\E2E;

use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;

/**
 * E2E smoke tests for the core payment flow.
 *
 * Run: phpunit --group e2e
 *
 * Targets the Stoplight mock API by default (GOPAY_PAYMENTS_V4_BASE_URL in .env).
 * Switch GOPAY_PAYMENTS_V4_BASE_URL to https://api.gopay.com/api/merchant/payments/4.0
 * and supply sandbox credentials for live sandbox testing.
 */
#[\PHPUnit\Framework\Attributes\Group('e2e')]
class PaymentsE2ETest extends E2ETestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function authenticate_succeeds(): void
    {
        $this->assertTrue($this->sdk->isAuthenticated());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function create_payment_returns_payment_details(): void
    {
        $payment = $this->sdk->createPayment($this->goid, $this->paymentParams());

        $this->assertInstanceOf(PaymentDetails::class, $payment);
        $this->assertNotEmpty($payment->getId());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_payment_status_returns_payment_details(): void
    {
        $payment = $this->sdk->createPayment($this->goid, $this->paymentParams());
        $status  = $this->sdk->getPaymentStatus((string) $payment->getId());

        $this->assertInstanceOf(PaymentDetails::class, $status);
        $this->assertSame($payment->getId(), $status->getId());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function charge_payment_with_card_token(): void
    {
        $cardToken = self::env('GOPAY_PAYMENTS_V4_CARD_TOKEN');
        if ($cardToken === null || $cardToken === '') {
            self::markTestSkipped(
                'Set GOPAY_PAYMENTS_V4_CARD_TOKEN to a browser-SDK-generated token to run this test.',
            );
        }

        $payment = $this->sdk->createPayment($this->goid, $this->paymentParams());

        $charge = $this->sdk->chargePayment((string) $payment->getId(), [
            'payment_instrument' => [
                'payment_instrument' => 'PAYMENT_CARD',
                'input'              => [
                    'input_type' => 'CARD_TOKEN',
                    'card_token' => $cardToken,
                ],
                'browser_data'       => [
                    'language'           => 'en-US',
                    'timezone'           => 0,
                    'screen_width'       => 1920,
                    'screen_height'      => 1080,
                    'color_depth'        => 24,
                    'user_agent'         => 'Mozilla/5.0 (phpunit)',
                    'accept_header'      => 'text/html',
                    'javascript_enabled' => true,
                ],
            ],
        ]);

        $this->assertInstanceOf(PaymentChargeResponse::class, $charge);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_browser_keys_returns_client_id_and_shareable_key(): void
    {
        $keys = $this->sdk->getBrowserKeys();

        $this->assertArrayHasKey('client_id', $keys);
        $this->assertArrayHasKey('shareable_key', $keys);
        $this->assertNotEmpty($keys['client_id']);
        $this->assertNotEmpty($keys['shareable_key']);
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function paymentParams(): array
    {
        return [
            'amount'       => 1990,
            'currency'     => 'CZK',
            'order_number' => 'E2E-' . time(),
            'customer'     => ['email' => 'e2e@example.com'],
            'callback'     => [
                'notification_url' => 'https://example.com/notify',
                'return_url'       => 'https://example.com/return',
            ],
        ];
    }
}

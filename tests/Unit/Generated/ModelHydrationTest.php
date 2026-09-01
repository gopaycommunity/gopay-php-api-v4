<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Generated;

use GoPay\Payments\Generated\Model\BankTransferRecipient;
use GoPay\Payments\Generated\Model\BrowserData;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\LinkStopReason;
use GoPay\Payments\Generated\Model\PaymentFromLinkRequest;
use GoPay\Payments\Generated\Model\PaymentChargeAction;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Generated\Model\QRCodeList;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\Generated\ObjectSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Sanity-checks for model hydration via ObjectSerializer.
 */
final class ModelHydrationTest extends TestCase
{
    public function testPaymentDetailsHydration(): void
    {
        $data = [
            'id'             => 'pay-001',
            'order_number'   => 'ORDER-001',
            'state'          => 'CREATED',
            'amount'         => 1000,
            'currency'       => 'CZK',
            'customer'       => ['email' => 'test@example.com'],
            'gw_url'         => 'https://gw.example.com',
            'payment_secret' => 'secret-xyz',
            'charge'         => ['id' => 'chg-001', 'state' => 'REQUESTED'],
        ];

        /** @var PaymentDetails $payment */
        $payment = ObjectSerializer::deserialize($data, PaymentDetails::class);

        $this->assertSame('pay-001', $payment->getId());
        $this->assertSame('ORDER-001', $payment->getOrderNumber());
        $this->assertSame('CREATED', $payment->getState());
        $this->assertSame(1000, $payment->getAmount());
        $this->assertSame('CZK', $payment->getCurrency());
        $this->assertSame('test@example.com', $payment->getCustomer()->getEmail());
        $this->assertSame('https://gw.example.com', $payment->getGwUrl());
        $this->assertSame('secret-xyz', $payment->getPaymentSecret());
        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $payment->getCharge());
        $this->assertSame('chg-001', $payment->getCharge()->getId());
    }

    public function testPaymentDetailsMissingFieldsAreNull(): void
    {
        /** @var PaymentDetails $payment */
        $payment = ObjectSerializer::deserialize([], PaymentDetails::class);

        $this->assertNull($payment->getId());
        $this->assertNull($payment->getState());
        $this->assertNull($payment->getCharge());
    }

    public function testPaymentChargeResponseHydration(): void
    {
        $data = [
            'id'     => 'chg-002',
            'state'  => 'ACTION_REQUIRED',
            'action' => ['redirect_url' => 'https://3ds.example.com', 'action_type' => 'EMV3DS'],
        ];

        /** @var PaymentChargeResponse $charge */
        $charge = ObjectSerializer::deserialize($data, PaymentChargeResponse::class);

        $this->assertSame('chg-002', $charge->getId());
        $this->assertSame('ACTION_REQUIRED', $charge->getState());
        $this->assertInstanceOf(PaymentChargeAction::class, $charge->getAction());
        $this->assertSame('https://3ds.example.com', $charge->getAction()->getRedirectUrl());
        $this->assertSame('EMV3DS', $charge->getAction()->getActionType());
    }

    public function testPaymentChargeResponseNoAction(): void
    {
        /** @var PaymentChargeResponse $charge */
        $charge = ObjectSerializer::deserialize(['id' => 'chg-003', 'state' => 'SUCCEEDED'], PaymentChargeResponse::class);

        $this->assertSame('SUCCEEDED', $charge->getState());
        $this->assertNull($charge->getAction());
    }

    public function testPaymentChargeStatusResponseHydration(): void
    {
        $data = [
            'id'          => 'chg-004',
            'state'       => 'FAILED',
            'fail_reason' => 'CARD_DECLINED',
        ];

        /** @var PaymentChargeStatusResponse $status */
        $status = ObjectSerializer::deserialize($data, PaymentChargeStatusResponse::class);

        $this->assertSame('FAILED', $status->getState());
        $this->assertSame('CARD_DECLINED', $status->getFailReason());
    }

    public function testPermanentCardTokenDetailsHydration(): void
    {
        $data = [
            'card_id'          => 'card-123',
            'masked_pan'       => '411111******1111',
            'expiration_month' => 12,
            'expiration_year'  => '28',
            'scheme'           => 'VISA',
            'corporate'        => false,
            'token'            => 'perm-token-abc',
            'status'           => 'ACTIVE',
        ];

        /** @var PermanentCardTokenDetails $card */
        $card = ObjectSerializer::deserialize($data, PermanentCardTokenDetails::class);

        $this->assertSame('card-123', $card->getCardId());
        $this->assertSame('411111******1111', $card->getMaskedPan());
        $this->assertSame('12', $card->getExpirationMonth());
        $this->assertSame('28', $card->getExpirationYear());
        $this->assertSame('VISA', $card->getScheme());
        $this->assertFalse($card->getCorporate());
        $this->assertSame('perm-token-abc', $card->getToken());
        $this->assertSame('ACTIVE', $card->getStatus());
    }

    public function testBrowserDataRequiresEmv3dsFields(): void
    {
        $data = [
            'language'           => 'cs-CZ',
            'timezone'           => -60,
            'screen_width'       => 1920,
            'screen_height'      => 1080,
            'color_depth'        => 24,
            'user_agent'         => 'Mozilla/5.0 (phpunit)',
            'accept_header'      => '{"accept":"text/html"}',
            'javascript_enabled' => true,
            'ip'                 => '192.0.2.42',
        ];

        /** @var BrowserData $complete */
        $complete = ObjectSerializer::deserialize($data, BrowserData::class);
        $this->assertTrue($complete->valid());

        $requiredFields = [
            'user_agent'         => "'userAgent' can't be null",
            'accept_header'      => "'acceptHeader' can't be null",
            'javascript_enabled' => "'javascriptEnabled' can't be null",
            'ip'                 => "'ip' can't be null",
        ];

        foreach ($requiredFields as $field => $expectedError) {
            $partial = $data;
            unset($partial[$field]);

            /** @var BrowserData $incomplete */
            $incomplete = ObjectSerializer::deserialize($partial, BrowserData::class);

            $this->assertFalse($incomplete->valid(), "BrowserData without {$field} must be invalid");
            $this->assertContains($expectedError, $incomplete->listInvalidProperties());
        }
    }

    public function testQrPaymentDetailsHydration(): void
    {
        /** @var QRPaymentDetails $qr */
        $qr = ObjectSerializer::deserialize([
            'amount'    => 1000,
            'currency'  => 'CZK',
            'recipient' => ['iban' => 'CZ1234567890'],
            'qr_code'   => ['spayd' => 'base64imagedata=='],
        ], QRPaymentDetails::class);

        $this->assertSame(1000, $qr->getAmount());
        $this->assertSame('CZK', $qr->getCurrency());
        $this->assertInstanceOf(BankTransferRecipient::class, $qr->getRecipient());
        $this->assertInstanceOf(QRCodeList::class, $qr->getQrCode());
    }

    public function testLinkDetailsHydration(): void
    {
        /** @var LinkDetails $link */
        $link = ObjectSerializer::deserialize([
            'id'         => '3405871122',
            'url'        => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active'     => true,
            'reusable'   => true,
            'expires_at' => '2026-08-18T14:35:12Z',
            'payment'    => [
                'amount'       => 15000,
                'currency'     => 'CZK',
                'order_number' => '2026-00042',
                'customer'     => ['email' => 'payer@example.com'],
                'callback'     => [
                    'notification_url' => 'https://eshop.example.com/gopay/notify',
                    'return_url'       => 'https://eshop.example.com/gopay/return',
                ],
                'additional_params' => [
                    ['name' => 'invoice', 'value' => 'INV-2026-001'],
                ],
            ],
        ], LinkDetails::class);

        $this->assertSame('3405871122', $link->getId());
        $this->assertTrue($link->getActive());
        $this->assertInstanceOf(\DateTime::class, $link->getExpiresAt());
        $this->assertInstanceOf(PaymentFromLinkRequest::class, $link->getPayment());
        $this->assertSame(15000, $link->getPayment()->getAmount());
        $this->assertSame('payer@example.com', $link->getPayment()->getCustomer()->getEmail());
        $this->assertTrue($link->valid());
    }

    /**
     * An active link carries no stop_reason and a never-expiring one no expires_at —
     * the v4 mapper omits nulls, so hydration must tolerate both being absent.
     */
    public function testLinkDetailsHydrationWithoutOptionalFields(): void
    {
        /** @var LinkDetails $link */
        $link = ObjectSerializer::deserialize([
            'id'       => '3405871122',
            'url'      => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active'   => true,
            'reusable' => true,
        ], LinkDetails::class);

        $this->assertNull($link->getExpiresAt());
        $this->assertNull($link->getStopReason());
        $this->assertNull($link->getPayment());
        $this->assertTrue($link->valid());
    }

    public function testLinkDetailsHydrationOfAConsumedOneShotLink(): void
    {
        /** @var LinkDetails $link */
        $link = ObjectSerializer::deserialize([
            'id'          => '3405871122',
            'url'         => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active'      => false,
            'reusable'    => false,
            'stop_reason' => 'USED',
        ], LinkDetails::class);

        $this->assertFalse($link->getActive());
        $this->assertSame(LinkStopReason::USED, $link->getStopReason());
        $this->assertTrue($link->valid());
    }

    public function testRefundDetailsHydration(): void
    {
        /** @var RefundDetails $refund */
        $refund = ObjectSerializer::deserialize([
            'id'         => 'ref-001',
            'state'      => 'SUCCESS',
            'amount'     => 500,
            'currency'   => 'CZK',
            'created_at' => '2024-01-15T10:00:00Z',
            'updated_at' => '2024-01-15T10:05:00Z',
        ], RefundDetails::class);

        $this->assertSame('ref-001', $refund->getId());
        $this->assertSame('SUCCESS', $refund->getState());
        $this->assertSame(500, $refund->getAmount());
        $this->assertInstanceOf(\DateTime::class, $refund->getCreatedAt());
    }

    /**
     * A FAILED refund carries no updated_at and no failure reason — the gateway
     * simply omits them, so hydration must tolerate their absence.
     */
    public function testFailedRefundDetailsHydrationWithoutUpdatedAt(): void
    {
        /** @var RefundDetails $refund */
        $refund = ObjectSerializer::deserialize([
            'id'         => 'ref-002',
            'state'      => 'FAILED',
            'amount'     => 40,
            'currency'   => 'CZK',
            'created_at' => '2024-01-15T10:00:00Z',
        ], RefundDetails::class);

        $this->assertSame('FAILED', $refund->getState());
        $this->assertNull($refund->getUpdatedAt());
    }
}

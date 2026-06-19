<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Generated;

use GoPay\Payments\Generated\Model\ChargeAction;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Generated\Model\QrPaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Generated\Model\RefundDetails;
use PHPUnit\Framework\TestCase;

/**
 * Sanity-checks for model hydration via fromArray().
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

        $payment = PaymentDetails::fromArray($data);

        $this->assertSame('pay-001', $payment->id);
        $this->assertSame('ORDER-001', $payment->orderNumber);
        $this->assertSame('CREATED', $payment->state);
        $this->assertSame(1000, $payment->amount);
        $this->assertSame('CZK', $payment->currency);
        $this->assertSame(['email' => 'test@example.com'], $payment->customer);
        $this->assertSame('https://gw.example.com', $payment->gwUrl);
        $this->assertSame('secret-xyz', $payment->paymentSecret);
        $this->assertInstanceOf(PaymentChargeStatusResponse::class, $payment->charge);
        $this->assertSame('chg-001', $payment->charge->id);

        // Getters
        $this->assertSame('pay-001', $payment->getId());
        $this->assertSame('CREATED', $payment->getState());
    }

    public function testPaymentDetailsMissingFieldsAreNull(): void
    {
        $payment = PaymentDetails::fromArray([]);

        $this->assertNull($payment->id);
        $this->assertNull($payment->state);
        $this->assertNull($payment->charge);
    }

    public function testPaymentChargeResponseHydration(): void
    {
        $data = [
            'id'     => 'chg-002',
            'state'  => 'AUTHENTICATION_PENDING',
            'action' => ['redirect_url' => 'https://3ds.example.com', 'action_type' => 'REDIRECT'],
        ];

        $charge = PaymentChargeResponse::fromArray($data);

        $this->assertSame('chg-002', $charge->id);
        $this->assertSame('AUTHENTICATION_PENDING', $charge->state);
        $this->assertInstanceOf(ChargeAction::class, $charge->action);
        $this->assertSame('https://3ds.example.com', $charge->action->redirectUrl);
        $this->assertSame('REDIRECT', $charge->action->actionType);
        $this->assertSame('https://3ds.example.com', $charge->action->getRedirectUrl());
        $this->assertSame('chg-002', $charge->getId());
        $this->assertNotNull($charge->getAction());
    }

    public function testPaymentChargeResponseNoAction(): void
    {
        $charge = PaymentChargeResponse::fromArray(['id' => 'chg-003', 'state' => 'SUCCEEDED']);

        $this->assertSame('SUCCEEDED', $charge->state);
        $this->assertNull($charge->action);
        $this->assertNull($charge->getAction());
    }

    public function testPaymentChargeStatusResponseHydration(): void
    {
        $data = [
            'id'         => 'chg-004',
            'state'      => 'FAILED',
            'fail_reason' => 'CARD_DECLINED',
        ];

        $status = PaymentChargeStatusResponse::fromArray($data);

        $this->assertSame('FAILED', $status->state);
        $this->assertSame('CARD_DECLINED', $status->failReason);
        $this->assertSame('FAILED', $status->getState());
    }

    public function testPermanentCardTokenDetailsHydration(): void
    {
        $data = [
            'card_id'            => 'card-123',
            'masked_pan'         => '411111******1111',
            'expiration_month'   => 12,
            'expiration_year'    => 2028,
            'scheme'             => 'VISA',
            'corporate'          => false,
            'token'              => 'perm-token-abc',
            'status'             => 'ACTIVE',
        ];

        $card = PermanentCardTokenDetails::fromArray($data);

        $this->assertSame('card-123', $card->cardId);
        $this->assertSame('411111******1111', $card->maskedPan);
        $this->assertSame(12, $card->expirationMonth);
        $this->assertSame(2028, $card->expirationYear);
        $this->assertSame('VISA', $card->scheme);
        $this->assertFalse($card->corporate);
        $this->assertSame('perm-token-abc', $card->token);
        $this->assertSame('ACTIVE', $card->status);
        $this->assertSame('card-123', $card->getCardId());
        $this->assertSame('perm-token-abc', $card->getToken());
    }

    public function testRefundDetailsHydration(): void
    {
        $refund = RefundDetails::fromArray([
            'id'         => 'ref-001',
            'state'      => 'REFUNDED',
            'amount'     => 500,
            'currency'   => 'CZK',
            'created_at' => '2024-01-15T10:00:00Z',
            'updated_at' => '2024-01-15T10:05:00Z',
        ]);

        $this->assertSame('ref-001', $refund->id);
        $this->assertSame('REFUNDED', $refund->state);
        $this->assertSame(500, $refund->amount);
        $this->assertSame('2024-01-15T10:00:00Z', $refund->createdAt);
        $this->assertSame('ref-001', $refund->getId());
    }

    public function testRecurrenceDetailsHydration(): void
    {
        $recurrence = RecurrenceDetails::fromArray([
            'id'    => 'rec-001',
            'type'  => 'ON_DEMAND',
            'state' => 'STARTED',
            'payment' => ['id' => 'pay-002', 'state' => 'PAID'],
        ]);

        $this->assertSame('rec-001', $recurrence->id);
        $this->assertSame('ON_DEMAND', $recurrence->type);
        $this->assertInstanceOf(PaymentDetails::class, $recurrence->payment);
        $this->assertSame('pay-002', $recurrence->payment->id);
        $this->assertSame('rec-001', $recurrence->getId());
    }

    public function testLinkDetailsHydration(): void
    {
        $link = LinkDetails::fromArray([
            'id'       => 'lnk-001',
            'url'      => 'https://pay.gopay.com/lnk-001',
            'active'   => true,
            'reusable' => false,
        ]);

        $this->assertSame('lnk-001', $link->id);
        $this->assertSame('https://pay.gopay.com/lnk-001', $link->url);
        $this->assertTrue($link->active);
        $this->assertFalse($link->reusable);
        $this->assertSame('lnk-001', $link->getId());
        $this->assertSame('https://pay.gopay.com/lnk-001', $link->getUrl());
    }

    public function testQrPaymentDetailsHydration(): void
    {
        $qr = QrPaymentDetails::fromArray([
            'amount'    => 1000,
            'currency'  => 'CZK',
            'recipient' => ['iban' => 'CZ1234567890'],
            'qr_code'   => ['spayd' => 'base64imagedata=='],
        ]);

        $this->assertSame(1000, $qr->amount);
        $this->assertSame('CZK', $qr->currency);
        $this->assertSame(['iban' => 'CZ1234567890'], $qr->recipient);
        $this->assertSame(['spayd' => 'base64imagedata=='], $qr->qrCode);
    }
}

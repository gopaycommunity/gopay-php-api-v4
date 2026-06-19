<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * QR payment information returned by getQrPaymentInfo().
 *
 * Contains the payment amount, recipient bank account details, and base64-encoded
 * QR code images in one or more formats (SPAYD, PayBySquare, SEPA, MNB).
 */
final class QrPaymentDetails implements ModelInterface
{
    public function __construct(
        /** Payment amount in minor units (cents / haléře). */
        public readonly ?int $amount = null,
        /** ISO 4217 currency code (e.g. 'CZK', 'EUR'). */
        public readonly ?string $currency = null,
        /**
         * Recipient bank account details (IBAN, BIC, account name, variable symbol, etc.).
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $recipient = null,
        /**
         * QR code images keyed by format ('spayd', 'paybysquare', 'sepa', 'mnb_qr').
         * Values are base64-encoded PNG or SVG strings depending on the `format` query parameter.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $qrCode = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            amount: isset($data['amount']) && is_int($data['amount']) ? $data['amount'] : null,
            currency: isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            recipient: isset($data['recipient']) && is_array($data['recipient']) ? $data['recipient'] : null,
            qrCode: isset($data['qr_code']) && is_array($data['qr_code']) ? $data['qr_code'] : null,
        );
    }
}

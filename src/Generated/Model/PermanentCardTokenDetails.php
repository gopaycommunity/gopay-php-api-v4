<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Permanent card token returned by getCardDetails() and tokenizeEncryptedCard().
 *
 * The `token` field is the permanent card token that you pass to chargePayment()
 * as `payment_instrument.input.card_token` for recurring or stored-card charges.
 *
 * Card numbers and CVV codes are NEVER exposed — only the masked PAN and token.
 */
final class PermanentCardTokenDetails implements ModelInterface
{
    public function __construct(
        /** Unique identifier for this stored card token. Use to call getCardDetails() or deleteCard(). */
        public readonly ?string $cardId = null,
        /** Masked primary account number, e.g. '411111******1111'. */
        public readonly ?string $maskedPan = null,
        /** Masked virtual PAN for tokenized cards (e.g. Apple Pay / Google Pay vaulted cards). */
        public readonly ?string $maskedVirtualPan = null,
        /** Card expiry month (1–12). */
        public readonly ?int $expirationMonth = null,
        /** Card expiry year (4 digits, e.g. 2028). */
        public readonly ?int $expirationYear = null,
        /** Card network scheme: 'VISA', 'MASTERCARD', 'AMEX', etc. */
        public readonly ?string $scheme = null,
        /** True for corporate/business cards. */
        public readonly ?bool $corporate = null,
        /** Card fingerprint — same physical card across merchants yields the same fingerprint. */
        public readonly ?string $fingerprint = null,
        /**
         * The permanent card token. Pass this as `card_token` in chargePayment() to charge this card.
         *
         * Example:
         * ```php
         * $card = $sdk->tokenizeEncryptedCard($jwePayload);
         * $sdk->chargePayment($paymentId, [
         *     'payment_instrument' => [
         *         'payment_instrument' => 'PAYMENT_CARD',
         *         'input' => ['input_type' => 'CARD_TOKEN', 'card_token' => $card->token],
         *     ],
         * ]);
         * ```
         */
        public readonly ?string $token = null,
        /** URL to the card artwork image for display in your UI. */
        public readonly ?string $cardArtUrl = null,
        /** Issuing bank / card brand name. */
        public readonly ?string $brand = null,
        /** Service type: 'TOKENIZED', 'STORED', etc. */
        public readonly ?string $serviceType = null,
        /** Token status: 'ACTIVE', 'SUSPENDED', 'EXPIRED', 'DELETED'. */
        public readonly ?string $status = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            cardId: isset($data['card_id']) && is_string($data['card_id']) ? $data['card_id'] : null,
            maskedPan: isset($data['masked_pan']) && is_string($data['masked_pan']) ? $data['masked_pan'] : null,
            maskedVirtualPan: isset($data['masked_virtual_pan']) && is_string($data['masked_virtual_pan']) ? $data['masked_virtual_pan'] : null,
            expirationMonth: isset($data['expiration_month']) && is_int($data['expiration_month']) ? $data['expiration_month'] : null,
            expirationYear: isset($data['expiration_year']) && is_int($data['expiration_year']) ? $data['expiration_year'] : null,
            scheme: isset($data['scheme']) && is_string($data['scheme']) ? $data['scheme'] : null,
            corporate: isset($data['corporate']) && is_bool($data['corporate']) ? $data['corporate'] : null,
            fingerprint: isset($data['fingerprint']) && is_string($data['fingerprint']) ? $data['fingerprint'] : null,
            token: isset($data['token']) && is_string($data['token']) ? $data['token'] : null,
            cardArtUrl: isset($data['card_art_url']) && is_string($data['card_art_url']) ? $data['card_art_url'] : null,
            brand: isset($data['brand']) && is_string($data['brand']) ? $data['brand'] : null,
            serviceType: isset($data['service_type']) && is_string($data['service_type']) ? $data['service_type'] : null,
            status: isset($data['status']) && is_string($data['status']) ? $data['status'] : null,
        );
    }

    public function getCardId(): ?string
    {
        return $this->cardId;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }
}

<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Payment entity returned by createPayment() and getPaymentStatus().
 *
 * IMPORTANT: The `gw_url` field is included for backward-compat only. Do NOT
 * redirect the customer to `gw_url`. The v4 flow is always:
 *   createPayment() → chargePayment() (with card token from the browser iframe)
 * See the SDK README and MIGRATION.md for the correct flow.
 */
final class PaymentDetails implements ModelInterface
{
    public function __construct(
        /** Unique payment ID. Use this to call chargePayment(), getChargeState(), etc. */
        public readonly ?string $id = null,
        /** Merchant-assigned order number. */
        public readonly ?string $orderNumber = null,
        /**
         * Payment state: CREATED, PAYMENT_AWAITED, PAID, CANCELLED, TIMEOUTED, etc.
         * See the API spec for the full list of Payment-State values.
         */
        public readonly ?string $state = null,
        /** Payment amount in minor units (cents / haléře). */
        public readonly ?int $amount = null,
        /** ISO 4217 currency code (e.g. 'CZK', 'EUR'). */
        public readonly ?string $currency = null,
        /**
         * Customer information (name, email, phone, etc.).
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $customer = null,
        /**
         * @deprecated DO NOT USE. Present for backward-compat with API v3 redirect flows.
         * This SDK always uses the create → charge flow. Never redirect to this URL.
         */
        public readonly ?string $gwUrl = null,
        /**
         * Current charge state, if a charge has been initiated.
         * Null until chargePayment() has been called.
         */
        public readonly ?PaymentChargeStatusResponse $charge = null,
        /** Server-to-server payment secret for validating IPN callbacks. */
        public readonly ?string $paymentSecret = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $chargeData = $data['charge'] ?? null;

        return new static(
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            orderNumber: isset($data['order_number']) && is_string($data['order_number']) ? $data['order_number'] : null,
            state: isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            amount: isset($data['amount']) && is_int($data['amount']) ? $data['amount'] : null,
            currency: isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            customer: isset($data['customer']) && is_array($data['customer']) ? $data['customer'] : null,
            gwUrl: isset($data['gw_url']) && is_string($data['gw_url']) ? $data['gw_url'] : null,
            charge: is_array($chargeData) ? PaymentChargeStatusResponse::fromArray($chargeData) : null,
            paymentSecret: isset($data['payment_secret']) && is_string($data['payment_secret']) ? $data['payment_secret'] : null,
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getState(): ?string
    {
        return $this->state;
    }
}

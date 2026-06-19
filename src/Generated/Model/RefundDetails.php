<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Refund resource returned by refundPayment(), listRefunds(), and getRefund().
 *
 * Refunds are first-class entities in API v4 with their own ID and lifecycle.
 */
final class RefundDetails implements ModelInterface
{
    public function __construct(
        /** Unique refund ID. Use this to call getRefund(). */
        public readonly ?string $id = null,
        /** Refund state: REQUESTED, PROCESSING, REFUNDED, CANCELLED, FAILED. */
        public readonly ?string $state = null,
        /** Refunded amount in minor units (cents / haléře). */
        public readonly ?int $amount = null,
        /** ISO 4217 currency code. */
        public readonly ?string $currency = null,
        /** ISO 8601 timestamp when the refund was created. */
        public readonly ?string $createdAt = null,
        /** ISO 8601 timestamp of the last state update. */
        public readonly ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            state: isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            amount: isset($data['amount']) && is_int($data['amount']) ? $data['amount'] : null,
            currency: isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null,
            createdAt: isset($data['created_at']) && is_string($data['created_at']) ? $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) && is_string($data['updated_at']) ? $data['updated_at'] : null,
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

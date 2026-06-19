<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Payment link returned by createPaymentLink() and linkStatus().
 *
 * A payment link is a shareable URL that the customer opens to complete payment.
 * It can be single-use (reusable = false) or multi-use (reusable = true).
 */
final class LinkDetails implements ModelInterface
{
    public function __construct(
        /** Whether this link can be used multiple times. */
        public readonly ?bool $reusable = null,
        /** Unique link ID. Use this to call linkStatus() or disableLink(). */
        public readonly ?string $id = null,
        /** The shareable payment URL to send to the customer. */
        public readonly ?string $url = null,
        /** Whether the link is still active (can be used to pay). */
        public readonly ?bool $active = null,
        /** ISO 8601 expiry timestamp after which the link can no longer be used. */
        public readonly ?string $expiresAt = null,
        /**
         * Embedded payment configuration (amount, currency, callback, etc.).
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $payment = null,
        /** Reason why the link was stopped/disabled (when active = false). */
        public readonly ?string $stopReason = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            reusable: isset($data['reusable']) && is_bool($data['reusable']) ? $data['reusable'] : null,
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            url: isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            active: isset($data['active']) && is_bool($data['active']) ? $data['active'] : null,
            expiresAt: isset($data['expires_at']) && is_string($data['expires_at']) ? $data['expires_at'] : null,
            payment: isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : null,
            stopReason: isset($data['stop_reason']) && is_string($data['stop_reason']) ? $data['stop_reason'] : null,
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }
}

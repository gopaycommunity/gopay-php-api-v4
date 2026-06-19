<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Response from chargePayment().
 *
 * After a successful charge the `state` may be SUCCEEDED (no 3DS needed) or
 * AUTHENTICATION_PENDING (3DS required — redirect to `action->redirectUrl`).
 *
 * Example:
 * ```php
 * $charge = $sdk->chargePayment($paymentId, [...]);
 *
 * if ($charge->action?->redirectUrl !== null) {
 *     // 3DS authentication required — redirect the customer
 *     header('Location: ' . $charge->action->redirectUrl);
 *     exit;
 * }
 * // No 3DS needed — poll for final state
 * $sdk->awaitChargeState($paymentId);
 * ```
 */
final class PaymentChargeResponse implements ModelInterface
{
    public function __construct(
        /** Unique charge ID. */
        public readonly ?string $id = null,
        /**
         * Initial charge state: REQUESTED, PROCESSING, SUCCEEDED, AUTHENTICATION_PENDING, FAILED.
         */
        public readonly ?string $state = null,
        /**
         * Payment instrument details used for this charge.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $paymentInstrument = null,
        /** Merchant return URL used after 3DS authentication. */
        public readonly ?string $returnUrl = null,
        /**
         * Action required for this charge. Non-null when 3DS authentication is needed;
         * access `$charge->action->redirectUrl` to get the URL to redirect the customer to.
         */
        public readonly ?ChargeAction $action = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $actionData = $data['action'] ?? null;

        return new static(
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            state: isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            paymentInstrument: isset($data['payment_instrument']) && is_array($data['payment_instrument']) ? $data['payment_instrument'] : null,
            returnUrl: isset($data['return_url']) && is_string($data['return_url']) ? $data['return_url'] : null,
            action: is_array($actionData) ? ChargeAction::fromArray($actionData) : null,
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

    /** Returns the charge action (e.g. 3DS redirect info), or null if no action needed. */
    public function getAction(): ?ChargeAction
    {
        return $this->action;
    }
}

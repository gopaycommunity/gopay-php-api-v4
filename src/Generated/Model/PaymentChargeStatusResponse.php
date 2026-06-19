<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Charge status returned by getChargeState() and awaitChargeState().
 *
 * Poll `state` until it reaches a terminal value:
 *   - SUCCEEDED  — charge completed successfully
 *   - FAILED     — charge failed; inspect `failReason` for details
 *   - CANCELLED  — charge was cancelled
 *
 * Non-terminal states: REQUESTED, PROCESSING, AUTHENTICATION_PENDING
 */
final class PaymentChargeStatusResponse implements ModelInterface
{
    public function __construct(
        /** Unique charge ID. */
        public readonly ?string $id = null,
        /**
         * Charge state: REQUESTED, PROCESSING, SUCCEEDED, FAILED, CANCELLED, AUTHENTICATION_PENDING.
         * awaitChargeState() polls until SUCCEEDED or FAILED.
         */
        public readonly ?string $state = null,
        /**
         * Payment instrument details (type, card info, etc.).
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $paymentInstrument = null,
        /** Merchant return URL used after 3DS authentication. */
        public readonly ?string $returnUrl = null,
        /** Action required for this charge (e.g. 3DS redirect). */
        public readonly ?ChargeAction $action = null,
        /** Reason for failure when state = FAILED (e.g. 'CARD_DECLINED'). */
        public readonly ?string $failReason = null,
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
            failReason: isset($data['fail_reason']) && is_string($data['fail_reason']) ? $data['fail_reason'] : null,
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

    public function getAction(): ?ChargeAction
    {
        return $this->action;
    }
}

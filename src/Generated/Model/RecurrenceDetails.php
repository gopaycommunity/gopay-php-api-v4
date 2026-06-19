<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * Recurrence agreement returned by createRecurrence() and recurrenceStatus().
 *
 * A recurrence is a standalone entity in API v4 (unlike v3 where it was attached
 * to a payment). Create it, then call startRecurrence() to trigger the first charge,
 * and recurrenceNext() for subsequent instalments.
 */
final class RecurrenceDetails implements ModelInterface
{
    public function __construct(
        /** Recurrence type: 'ON_DEMAND' or 'SCHEDULED'. */
        public readonly ?string $type = null,
        /** Unique recurrence ID. Use this to call recurrenceStatus(), stopRecurrence(), etc. */
        public readonly ?string $id = null,
        /**
         * Embedded payment details for this recurrence instalment.
         * Available after the recurrence has been started.
         */
        public readonly ?PaymentDetails $payment = null,
        /**
         * Recurrence schedule configuration (for SCHEDULED type).
         * Contains: interval, start_date, end_date, etc.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $schedule = null,
        /** Recurrence state: CREATED, STARTED, STOPPED, SUSPENDED, COMPLETED. */
        public readonly ?string $state = null,
        /** Reason for stopping (when state = STOPPED). */
        public readonly ?string $stopReason = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $paymentData = $data['payment'] ?? null;

        return new static(
            type: isset($data['type']) && is_string($data['type']) ? $data['type'] : null,
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            payment: is_array($paymentData) ? PaymentDetails::fromArray($paymentData) : null,
            schedule: isset($data['schedule']) && is_array($data['schedule']) ? $data['schedule'] : null,
            state: isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            stopReason: isset($data['stop_reason']) && is_string($data['stop_reason']) ? $data['stop_reason'] : null,
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

<?php

declare(strict_types=1);

namespace GoPay\Payments\Event;

use GoPay\Payments\Generated\Model\CardDeletedEvent;
use GoPay\Payments\Generated\Model\CardUpdatedEvent;
use GoPay\Payments\Generated\Model\EventType;
use GoPay\Payments\Generated\Model\LinkDisabledEvent;
use GoPay\Payments\Generated\Model\LinkPaymentCreatedEvent;
use GoPay\Payments\Generated\Model\PaymentCanceledEvent;
use GoPay\Payments\Generated\Model\PaymentPaidEvent;
use GoPay\Payments\Generated\Model\PaymentTimeoutedEvent;
use GoPay\Payments\Generated\Model\RecurrencePaymentCreatedEvent;
use GoPay\Payments\Generated\Model\RecurrenceStartedEvent;
use GoPay\Payments\Generated\Model\RecurrenceStoppedEvent;
use GoPay\Payments\Generated\Model\RefundFailedEvent;
use GoPay\Payments\Generated\Model\RefundSucceededEvent;
use GoPay\Payments\Generated\ObjectSerializer;

/**
 * Turns a decoded event into the model of its `type`.
 *
 * The generator cannot do this itself: openapi-generator 7.9.0 flattens the `Event` oneOf into
 * one class whose `data` is typed as the last variant's (`CardEventData`), so a payment event
 * would lose its `payment_id`. The spec has no discriminator to switch on either — every
 * variant's `type` is the whole `Event-Type` enum — so the mapping is written out here.
 *
 * @internal
 *
 * @phpstan-type GoPayEvent PaymentPaidEvent|PaymentCanceledEvent|PaymentTimeoutedEvent|LinkPaymentCreatedEvent|LinkDisabledEvent|RefundSucceededEvent|RefundFailedEvent|RecurrenceStartedEvent|RecurrencePaymentCreatedEvent|RecurrenceStoppedEvent|CardUpdatedEvent|CardDeletedEvent|UnknownEvent
 */
final class EventFactory
{
    /** @var array<string, class-string> */
    private const MODELS = [
        EventType::PAYMENTS_PAID               => PaymentPaidEvent::class,
        EventType::PAYMENTS_CANCELED           => PaymentCanceledEvent::class,
        EventType::PAYMENTS_TIMEOUTED          => PaymentTimeoutedEvent::class,
        EventType::LINKS_PAYMENT_CREATED       => LinkPaymentCreatedEvent::class,
        EventType::LINKS_DISABLED              => LinkDisabledEvent::class,
        EventType::REFUNDS_SUCCEEDED           => RefundSucceededEvent::class,
        EventType::REFUNDS_FAILED              => RefundFailedEvent::class,
        EventType::RECURRENCES_STARTED         => RecurrenceStartedEvent::class,
        EventType::RECURRENCES_PAYMENT_CREATED => RecurrencePaymentCreatedEvent::class,
        EventType::RECURRENCES_STOPPED         => RecurrenceStoppedEvent::class,
        EventType::CARDS_UPDATED               => CardUpdatedEvent::class,
        EventType::CARDS_DELETED               => CardDeletedEvent::class,
    ];

    /**
     * Checks the fields every event has, so that a known `type` can be trusted to carry its
     * `data`, and returns the typed model — or an {@see UnknownEvent} for a type this SDK does
     * not know, never an error: the API asks for an unknown type to be ignored.
     *
     * @throws \UnexpectedValueException when the value is not an event, or a field does not parse
     *
     * @return GoPayEvent
     */
    public static function fromDecoded(mixed $event): object
    {
        if (
            !$event instanceof \stdClass
            || !is_string($event->id ?? null)
            || !is_string($event->type ?? null)
            || !is_string($event->occurred_at ?? null)
            || !($event->data ?? null) instanceof \stdClass
        ) {
            throw new \UnexpectedValueException('not an event');
        }

        try {
            $model = self::MODELS[$event->type] ?? null;
            if ($model === null) {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) json_encode($event->data), true);

                return new UnknownEvent($event->id, $event->type, new \DateTime($event->occurred_at), $data);
            }

            /** @var GoPayEvent */
            return ObjectSerializer::deserialize($event, $model);
        } catch (\Exception $e) {
            // ObjectSerializer raises InvalidArgumentException on an enum value it does not know
            // and DateTime an Exception on a malformed instant. Neither belongs to the caller.
            throw new \UnexpectedValueException('an event field does not parse', 0, $e);
        }
    }
}

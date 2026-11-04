<?php

declare(strict_types=1);

namespace GoPay\Payments\Event;

/**
 * An event whose `type` this SDK version does not know — one GoPay added after the release.
 *
 * Returned instead of an error, so a new event type does not break a working integration: the
 * API asks merchants to ignore a type they do not recognise. Its `data` stays as decoded JSON,
 * since the SDK has no model for it. Upgrading the SDK turns it into a typed event.
 */
final class UnknownEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly \DateTime $occurredAt,
        private readonly array $data,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTime
    {
        return clone $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}

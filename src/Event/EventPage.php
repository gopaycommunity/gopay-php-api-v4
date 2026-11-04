<?php

declare(strict_types=1);

namespace GoPay\Payments\Event;

/**
 * One page of `GET /eshops/{goid}/events`, newest event first.
 *
 * `nextPageUrl` is set while older matching events remain. Pass it to nothing yourself:
 * {@see \GoPay\Payments\GoPayClient::iterateEvents()} follows it for you.
 *
 * @phpstan-import-type GoPayEvent from EventFactory
 */
final class EventPage
{
    /**
     * @param list<GoPayEvent> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly ?string $nextPageUrl,
    ) {}

    /**
     * Each item is the typed model of its `type` (e.g. `PaymentPaidEvent`), or an
     * {@see UnknownEvent} for a type this SDK version does not know.
     *
     * @return list<GoPayEvent>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getNextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }
}

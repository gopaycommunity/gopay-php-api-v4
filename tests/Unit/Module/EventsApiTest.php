<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Event\EventPage;
use GoPay\Payments\Event\UnknownEvent;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\CardDeletedEvent;
use GoPay\Payments\Generated\Model\LinkPaymentCreatedEvent;
use GoPay\Payments\Generated\Model\PaymentPaidEvent;
use GoPay\Payments\Generated\Model\RecurrenceStoppedEvent;
use GoPay\Payments\Generated\Model\RefundFailedEvent;
use GoPay\Payments\Module\EventsApi;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class EventsApiTest extends ModuleTestCase
{
    private EventsApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new EventsApi($this->http);
    }

    #[Test]
    public function list_events_sends_the_filters_and_dispatches_each_item(): void
    {
        $this->queueJson([
            'items' => [
                self::event('e3', 'payments.paid', ['payment_id' => 'p1', 'charge_id' => 'c1']),
                self::event('e2', 'refunds.failed', ['payment_id' => 'p1', 'refund_id' => 'r1']),
                self::event('e1', 'payouts.sent', ['payout_id' => 'x']),
            ],
            'next_page_url' => 'https://gw.example.com/gp-gw/api/4.0/eshops/8123456789/events?max_items=3&prev_item=e1',
        ]);

        $page = $this->api->listEvents('8123456789', [
            'max_items'      => 3,
            'type'           => 'payments.*,refunds.failed',
            'occurred_after' => new \DateTimeImmutable('2026-10-01T00:00:00+02:00'),
            'related_type'   => null,
        ]);

        $request = $this->lastRequest();
        $this->assertSame('/eshops/8123456789/events', $request->getUri()->getPath());
        $this->assertSame(
            'max_items=3&type=payments.%2A%2Crefunds.failed&occurred_after=2026-10-01T00%3A00%3A00%2B02%3A00',
            $request->getUri()->getQuery(),
        );
        $this->assertInstanceOf(EventPage::class, $page);
        [$paid, $refund, $unknown] = $page->getItems();
        $this->assertInstanceOf(PaymentPaidEvent::class, $paid);
        $this->assertSame('c1', $paid->getData()->getChargeId());
        $this->assertInstanceOf(RefundFailedEvent::class, $refund);
        $this->assertSame('r1', $refund->getData()->getRefundId());
        $this->assertInstanceOf(UnknownEvent::class, $unknown);
        $this->assertSame(['payout_id' => 'x'], $unknown->getData());
        $this->assertSame(
            'https://gw.example.com/gp-gw/api/4.0/eshops/8123456789/events?max_items=3&prev_item=e1',
            $page->getNextPageUrl(),
        );
    }

    #[Test]
    public function list_events_without_filters_sends_no_query(): void
    {
        $this->queueJson(['items' => []]);

        $page = $this->api->listEvents('8123456789');

        $this->assertSame('', $this->lastRequest()->getUri()->getQuery());
        $this->assertSame([], $page->getItems());
        $this->assertNull($page->getNextPageUrl());
    }

    #[Test]
    public function get_event_escapes_the_id_and_dispatches(): void
    {
        $this->queueJson(self::event('e/1', 'links.payment_created', ['link_id' => 'l1', 'payment_id' => 'p1']));

        $event = $this->api->getEvent('8123456789', 'e/1');

        $this->assertSame('/eshops/8123456789/events/e%2F1', $this->lastRequest()->getUri()->getPath());
        $this->assertInstanceOf(LinkPaymentCreatedEvent::class, $event);
        $this->assertSame('l1', $event->getData()->getLinkId());
    }

    #[Test]
    public function get_event_passes_a_404_through(): void
    {
        $this->mockClient->addResponse(new Response(404, [], '{"errors":[{"error_code":"NOT_FOUND"}]}'));

        $this->expectException(GoPayHttpException::class);
        $this->api->getEvent('8123456789', 'missing');
    }

    #[Test]
    public function iterate_events_follows_next_page_url_only_through_the_configured_base_url(): void
    {
        $this->queueJson([
            'items' => [self::event('e4', 'cards.deleted', ['card_id' => 'k1']), self::event('e3', 'cards.deleted', ['card_id' => 'k2'])],
            // A host other than the client's: the token must not follow it there.
            'next_page_url' => 'https://elsewhere.example.org/gp-gw/api/4.0/eshops/8123456789/events?type=cards.%2A&max_items=2&prev_item=e3',
        ]);
        $this->queueJson([
            'items' => [self::event('e2', 'recurrences.stopped', ['recurrence_id' => 'rc1'])],
        ]);

        $ids = [];
        foreach ($this->api->iterateEvents('8123456789', ['type' => 'cards.*', 'max_items' => 2]) as $event) {
            $ids[] = $event->getId();
        }

        $this->assertSame(['e4', 'e3', 'e2'], $ids);
        $requests = $this->mockClient->getRequests();
        $this->assertCount(2, $requests);
        $second = $requests[1]->getUri();
        $this->assertSame('mock.example.com', $second->getHost());
        $this->assertSame('/eshops/8123456789/events', $second->getPath());
        $this->assertSame('type=cards.%2A&max_items=2&prev_item=e3', $second->getQuery());
        $this->assertSame('Bearer test-token', $requests[1]->getHeaderLine('Authorization'));
    }

    #[Test]
    public function iterate_events_fetches_a_page_only_when_the_previous_one_is_used_up(): void
    {
        $this->queueJson([
            'items' => [self::event('e2', 'recurrences.stopped', ['recurrence_id' => 'rc1'])],
            'next_page_url' => '/gp-gw/api/4.0/eshops/8123456789/events?prev_item=e2',
        ]);

        $events = $this->api->iterateEvents('8123456789');
        $this->assertCount(0, $this->mockClient->getRequests(), 'nothing is fetched before the first item is asked for');

        $this->assertInstanceOf(RecurrenceStoppedEvent::class, $events->current());
        $this->assertCount(1, $this->mockClient->getRequests());
    }

    #[Test]
    public function iterate_events_refuses_a_next_page_url_with_no_query(): void
    {
        $this->queueJson(['items' => [], 'next_page_url' => 'https://gw.example.com/gp-gw/api/4.0/eshops/1/events']);

        $e = $this->catch(fn() => iterator_to_array($this->api->iterateEvents('8123456789')));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        $this->assertCount(1, $this->mockClient->getRequests());
    }

    #[Test]
    public function iterate_events_refuses_a_next_page_url_of_nothing_but_ampersands(): void
    {
        $this->queueJson(['items' => [], 'next_page_url' => '/x/events?&&=']);

        $e = $this->catch(fn() => iterator_to_array($this->api->iterateEvents('8123456789')));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function brokenPages(): iterable
    {
        yield 'no items' => [['next_page_url' => null]];
        yield 'items an object' => [['items' => ['a' => self::event('e', 'payments.paid', ['payment_id' => 'p'])]]];
        yield 'next page a number' => [['items' => [], 'next_page_url' => 5]];
        yield 'item not an event' => [['items' => [['id' => 'e']]]];
        yield 'item with a bad instant' => [['items' => [['id' => 'e', 'type' => 'payments.paid', 'occurred_at' => 'soon', 'data' => ['payment_id' => 'p']]]]];
    }

    #[Test]
    #[DataProvider('brokenPages')]
    public function a_page_that_is_not_a_list_of_events_is_an_unexpected_response(mixed $page): void
    {
        $this->queueJson($page);

        $e = $this->catch(fn() => $this->api->listEvents('8123456789'));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
    }

    #[Test]
    public function a_page_that_does_not_read_carries_the_ids_of_its_request(): void
    {
        $this->queueJson(['items' => [], 'next_page_url' => 5]);

        $e = $this->catch(fn() => $this->api->listEvents('8123456789'));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        $this->assertSame($this->lastRequest()->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
        $this->assertSame($this->lastRequest()->getHeaderLine('X-GoPay-Transaction-Id'), $e->transactionId);
    }

    #[Test]
    public function an_event_that_does_not_read_carries_the_ids_of_its_request(): void
    {
        $this->queueJson(['id' => 'e1']);

        $e = $this->catch(fn() => $this->api->getEvent('8123456789', 'e1'));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        $this->assertSame($this->lastRequest()->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
    }

    #[Test]
    public function the_next_page_keeps_repeated_parameters_and_drops_empty_pairs(): void
    {
        $this->queueJson([
            'items' => [self::event('e3', 'refunds.failed', ['payment_id' => 'p', 'refund_id' => 'r'])],
            'next_page_url' => '/gp-gw/api/4.0/eshops/1/events?type=payments.paid&&type=refunds.failed&=x&max_items=1&prev_item=e3&',
        ]);
        $this->queueJson(['items' => []]);

        iterator_to_array($this->api->iterateEvents('8123456789'));

        $this->assertSame('type=payments.paid&type=refunds.failed&max_items=1&prev_item=e3', $this->lastRequest()->getUri()->getQuery());
    }

    #[Test]
    public function iterator_to_array_keeps_every_event_across_pages(): void
    {
        $this->queueJson([
            'items' => [self::event('e3', 'cards.deleted', ['card_id' => 'k']), self::event('e2', 'cards.deleted', ['card_id' => 'k'])],
            'next_page_url' => '/x?prev_item=e2',
        ]);
        $this->queueJson(['items' => [self::event('e1', 'cards.deleted', ['card_id' => 'k'])], 'next_page_url' => '']);

        $events = iterator_to_array($this->api->iterateEvents('8123456789'));

        $this->assertSame([0, 1, 2], array_keys($events));
        $this->assertSame(['e3', 'e2', 'e1'], array_map(static fn($e) => (string) $e->getId(), $events));
        $this->assertCount(2, $this->mockClient->getRequests(), 'an empty next_page_url ends the paging');
    }

    #[Test]
    public function a_known_type_is_typed_even_when_listed_with_a_type_it_does_not_know(): void
    {
        $this->queueJson(self::event('e1', 'cards.deleted', ['card_id' => 'k1', 'added_later' => true]));

        $event = $this->api->getEvent('8123456789', 'e1');

        $this->assertInstanceOf(CardDeletedEvent::class, $event);
        $this->assertSame('k1', $event->getData()->getCardId());
    }

    #[Test]
    public function rejects_an_empty_goid_without_a_request(): void
    {
        $e = $this->catch(fn() => $this->api->listEvents(' '));

        $this->assertSame(ErrorCode::InvalidArgument, $e->errorCode);
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function event(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type, 'occurred_at' => '2026-10-27T14:37:27.167Z', 'data' => $data];
    }

    private function catch(callable $call): GoPaySdkException
    {
        try {
            $call();
        } catch (GoPaySdkException $e) {
            return $e;
        }
        $this->fail('expected a GoPaySdkException');
    }
}

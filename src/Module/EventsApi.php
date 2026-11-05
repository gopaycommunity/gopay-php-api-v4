<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Event\EventFactory;
use GoPay\Payments\Event\EventPage;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Http\HttpClient;

/**
 * Events module — the eshop's events, the same ones webhooks deliver, kept for 30 days.
 *
 * @phpstan-import-type GoPayEvent from EventFactory
 *
 * @phpstan-type ListEventsParams array<string, string|int|\DateTimeInterface|null>
 */
final class EventsApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * @param ListEventsParams $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function listEvents(string $goid, array $params = []): EventPage
    {
        return $this->page($this->requirePathSegment($goid, 'goid'), self::query($params));
    }

    /**
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return GoPayEvent
     */
    public function getEvent(string $goid, string $eventId): object
    {
        $gid = $this->requirePathSegment($goid, 'goid');
        $eid = $this->requirePathSegment($eventId, 'eventId');

        return $this->client->getObjectAs("/eshops/{$gid}/events/{$eid}", fn(\stdClass $event): object => $this->event($event));
    }

    /**
     * Every matching event, page after page, fetching the next page only when the previous one
     * is used up. Keys run on across pages, so `iterator_to_array()` keeps every event.
     *
     * Only the query of `next_page_url` is used, sent to the base URL this client was
     * configured with. Following the URL itself would hand the access token to whatever host
     * it names, and its path prefix is the gateway's own (`/gp-gw/api/4.0`), not necessarily
     * the one the client was configured with.
     *
     * @param ListEventsParams $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return \Generator<int, GoPayEvent, mixed, void>
     */
    public function iterateEvents(string $goid, array $params = []): \Generator
    {
        $gid = $this->requirePathSegment($goid, 'goid');
        $query = self::query($params);
        /** @var array<string, true> $fetched the queries already sent, against a cursor that does not move */
        $fetched = [];
        while (true) {
            $fetched[$query] = true;
            $page = $this->page($gid, $query, $fetched);
            // One by one: `yield from` would restart the keys at 0 on every page.
            foreach ($page->getItems() as $event) {
                yield $event;
            }
            $next = $page->getNextPageUrl();
            if ($next === null) {
                return;
            }
            // Checked when the page was read, so it has a query.
            $query = (string) self::nextPageQuery($next);
        }
    }

    /**
     * @param array<string, true>|null $fetched When paging: the queries already sent. A page that
     *                                          points back at one of them, or that is empty and
     *                                          still has a next page, would page forever.
     */
    private function page(string $gid, string $query, ?array $fetched = null): EventPage
    {
        return $this->client->getObjectAs(
            "/eshops/{$gid}/events" . ($query !== '' ? "?{$query}" : ''),
            fn(\stdClass $page): EventPage => $this->readPage($page, $fetched),
        );
    }

    /**
     * Runs while its request is the one in flight, so an error raised here carries its ids.
     *
     * @param array<string, true>|null $fetched
     */
    private function readPage(\stdClass $page, ?array $fetched): EventPage
    {
        $items = $page->items ?? null;
        $next = $page->next_page_url ?? null;
        if ($next === '') {
            $next = null;
        }
        if (
            !is_array($items)
            || !array_is_list($items)
            || ($next !== null && (!is_string($next) || self::nextPageQuery($next) === null))
        ) {
            $this->client->emitError(self::unexpected());
        }
        if ($fetched !== null && $next !== null && ($items === [] || isset($fetched[(string) self::nextPageQuery($next)]))) {
            $this->client->emitError(new GoPaySdkException(
                '[GoPaySDK] Failed to read the events response: next_page_url does not move past the page it came with, so paging would never end.',
                ErrorCode::UnexpectedResponse,
            ));
        }
        $events = [];
        foreach ($items as $item) {
            $events[] = $this->event($item);
        }

        return new EventPage($events, $next);
    }

    /** @return GoPayEvent */
    private function event(mixed $decoded): object
    {
        try {
            return EventFactory::fromDecoded($decoded);
        } catch (\UnexpectedValueException $e) {
            $this->client->emitError(self::unexpected($e));
        }
    }

    /**
     * RFC 3986 encoding, so the `+` of a time zone offset arrives as `%2B` and not as a space.
     * Instants keep their milliseconds: the API orders events by them, and an instant cut to
     * the second would move an `occurred_after` bound back.
     *
     * @param ListEventsParams $params
     */
    private static function query(array $params): string
    {
        $values = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $values[$name] = $value instanceof \DateTimeInterface
                ? $value->format(\DateTimeInterface::RFC3339_EXTENDED)
                : (string) $value;
        }

        return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The query of `next_page_url` as the gateway wrote it — encoding untouched, a repeated
     * parameter sent repeated — less the empty pairs a stray `&` leaves. Null when nothing is left.
     */
    private static function nextPageQuery(string $nextPageUrl): ?string
    {
        $query = parse_url($nextPageUrl, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }
        $pairs = array_filter(
            explode('&', $query),
            static fn(string $pair): bool => $pair !== '' && !str_starts_with($pair, '='),
        );

        return $pairs === [] ? null : implode('&', $pairs);
    }

    private static function unexpected(?\Throwable $previous = null): GoPaySdkException
    {
        return new GoPaySdkException(
            '[GoPaySDK] Failed to read the events response: it is not a list of events.',
            ErrorCode::UnexpectedResponse,
            $previous,
        );
    }
}

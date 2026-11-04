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
        $gid = $this->requirePathSegment($goid, 'goid');
        $query = self::query($params);
        $page = $this->client->getObject("/eshops/{$gid}/events" . ($query !== '' ? "?{$query}" : ''));

        $items = $page->items ?? null;
        $next = $page->next_page_url ?? null;
        if (!is_array($items) || !array_is_list($items) || ($next !== null && !is_string($next))) {
            $this->client->emitError(self::unexpected());
        }
        $events = [];
        foreach ($items as $item) {
            $events[] = $this->event($item);
        }

        return new EventPage($events, $next);
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

        return $this->event($this->client->getObject("/eshops/{$gid}/events/{$eid}"));
    }

    /**
     * Every matching event, page after page, fetching the next page only when the previous one
     * is used up.
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
        $page = $this->listEvents($goid, $params);
        for (;;) {
            yield from $page->getItems();
            $next = $page->getNextPageUrl();
            if ($next === null || $next === '') {
                return;
            }
            $page = $this->listEvents($goid, $this->nextPageParams($next));
        }
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
                ? $value->format(\DateTimeInterface::RFC3339)
                : (string) $value;
        }

        return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, string> */
    private function nextPageParams(string $nextPageUrl): array
    {
        $query = parse_url($nextPageUrl, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            $this->client->emitError(self::unexpected());
        }
        $params = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            // Read by hand: parse_str() would turn a `.` in a name into `_` and nest `a[b]`.
            $params[rawurldecode(str_replace('+', ' ', $name))] = rawurldecode(str_replace('+', ' ', $value));
        }

        return $params;
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

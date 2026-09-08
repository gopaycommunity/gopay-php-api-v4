<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Http\HttpClient;

/**
 * Recurrences module — create and manage recurring payment agreements.
 *
 * A recurrence is a standalone entity, not something attached to an existing
 * payment the way v3's `createRecurrence($paymentId, …)` was. Creating one only
 * stores a payment template; no payment exists until the recurrence is started.
 *
 * The lifecycle is create → start → next, and the states do not line up with the
 * method names: `createRecurrence()` leaves the recurrence `NEW`, `startRecurrence()`
 * creates the first payment and moves it to `REQUESTED`, and only once the customer
 * has paid that first payment does it become `STARTED`. `recurrenceNext()` answers
 * 409 for the whole `REQUESTED` window, so a caller that starts a recurrence and
 * immediately asks for the next payment gets a conflict, not a payment.
 *
 * Two kinds exist, told apart by `type` in the create body. An `AUTO` recurrence
 * carries a `schedule` and GoPay creates the payments itself; an `ON_DEMAND` one
 * must not carry a schedule, because the merchant asks for each further payment
 * with {@see self::recurrenceNext()}.
 */
final class RecurrencesApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Create a recurrence. Returns it in state `NEW`.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /eshops/{goid}/recurrences
     *
     * The body is a discriminated union on `type`, and the two variants differ by
     * more than that one field:
     *
     * ```php
     * // AUTO — GoPay creates the payments on the schedule
     * $sdk->createRecurrence($goid, [
     *     'type'                => 'AUTO',
     *     'schedule'            => ['period' => 'MONTH', 'cycle' => 1],
     *     'recurrence_date_to'  => '2027-09-04',
     *     'payment'             => [...],
     * ]);
     *
     * // ON_DEMAND — the merchant asks for each further payment itself
     * $sdk->createRecurrence($goid, [
     *     'type'                => 'ON_DEMAND',
     *     'recurrence_date_to'  => '2027-09-04',
     *     'payment'             => [...],
     * ]);
     * ```
     *
     * `schedule` is required for `AUTO` and rejected for `ON_DEMAND`. Both variants
     * declare `additionalProperties: false`, so an unknown field — at any level,
     * including inside `payment.customer` — is a 400, not something the server
     * quietly drops. `recurrence_date_to` is required and is a plain `yyyy-MM-dd`
     * date: a value carrying a time is rejected. It belongs at the top level, never
     * inside `schedule`.
     *
     * Bodies are passed through as given rather than validated here, matching
     * {@see PaymentsApi::chargePayment()}, whose body is a discriminated union too.
     *
     * @param array<string, mixed> $params Create body: `type`, `recurrence_date_to`, `payment`,
     *                                     plus `schedule` for an `AUTO` recurrence.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createRecurrence(string $goid, array $params): RecurrenceDetails
    {
        $gid = $this->requirePathSegment($goid, 'goid');

        return $this->client->post("/eshops/{$gid}/recurrences", $params, RecurrenceDetails::class);
    }

    /**
     * Retrieve the current state of a recurrence and the payment it last created.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /recurrences/{rec_id}
     *
     * Before the recurrence is started, `payment` is the stored template: it carries
     * the amount, currency and customer but no `id` or `state` yet.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function recurrenceStatus(string $recId): RecurrenceDetails
    {
        $rid = $this->requirePathSegment($recId, 'recId');

        return $this->client->get("/recurrences/{$rid}", RecurrenceDetails::class);
    }

    /**
     * Stop a recurrence permanently.
     * Requires the `payment:write` OAuth2 scope.
     *
     * DELETE /recurrences/{rec_id}
     *
     * The recurrence moves to `STOPPED` with `stop_reason` `CANCELLED_VIA_API` and
     * creates no further payments. This cannot be undone: stopping an already
     * stopped recurrence answers 409, and starting one answers 409 as well.
     * Payments the recurrence already created are unaffected — cancel or refund
     * those individually.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function stopRecurrence(string $recId): void
    {
        $rid = $this->requirePathSegment($recId, 'recId');
        $this->client->delete("/recurrences/{$rid}");
    }

    /**
     * Start a recurrence by creating its first payment.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /recurrences/{rec_id}/start
     *
     * The recurrence moves to `REQUESTED`, not `STARTED`. The returned payment
     * carries `gw_url` and `payment_secret`; the customer pays it there, and only
     * then does the recurrence become `STARTED`. Calling this again while the first
     * payment is still in progress answers 409.
     *
     * The body is optional and overrides fields of the stored payment template for
     * this one payment: `amount`, `order_number`, `order_description`, `customer`
     * and `callback`. `customer` is merged field by field rather than replaced, and
     * may omit `email` or be empty. An unknown field anywhere in the override is a 400.
     *
     * @param array<string, mixed>|null $params Optional per-payment overrides.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function startRecurrence(string $recId, ?array $params = null): PaymentDetails
    {
        $rid = $this->requirePathSegment($recId, 'recId');

        return $this->client->post("/recurrences/{$rid}/start", $params, PaymentDetails::class);
    }

    /**
     * Create a further payment from a recurrence already in state `STARTED`.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /recurrences/{rec_id}/next
     *
     * Answers 409 for as long as the recurrence has not been started *and* its first
     * payment paid — the `REQUESTED` window between {@see self::startRecurrence()}
     * and the customer paying is not a state this can be called in.
     *
     * Takes the same optional per-payment overrides as {@see self::startRecurrence()}.
     *
     * @param array<string, mixed>|null $params Optional per-payment overrides.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function recurrenceNext(string $recId, ?array $params = null): PaymentDetails
    {
        $rid = $this->requirePathSegment($recId, 'recId');

        return $this->client->post("/recurrences/{$rid}/next", $params, PaymentDetails::class);
    }
}

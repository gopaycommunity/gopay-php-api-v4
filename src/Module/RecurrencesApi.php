<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Http\HttpClient;

/**
 * Recurrences module — create and manage recurring payment agreements.
 *
 * v4 redesign note: Recurrences are now standalone entities (not attached to
 * an existing payment like v3's createRecurrence($paymentId, ...)). Create a
 * recurrence first, then start it to trigger the first charge, and call next
 * for each subsequent instalment.
 */
final class RecurrencesApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Create a recurrence.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /eshops/{goid}/recurrences
     *
     * @param array<string, mixed> $params Recurrence creation parameters (type, schedule, payment data…).
     *
     * @throws GoPaySdkException
     */
    public function createRecurrence(string $goid, array $params): RecurrenceDetails
    {
        $gid = $this->requireNonEmpty($goid, 'goid');

        return $this->client->post("/eshops/{$gid}/recurrences", $params, RecurrenceDetails::class);
    }

    /**
     * Retrieve the current state of a recurrence.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /recurrences/{rec_id}
     *
     * @throws GoPaySdkException
     */
    public function recurrenceStatus(string $recId): RecurrenceDetails
    {
        $rid = $this->requireNonEmpty($recId, 'recId');

        return $this->client->get("/recurrences/{$rid}", RecurrenceDetails::class);
    }

    /**
     * Stop a recurrence permanently.
     * Requires the `payment:write` OAuth2 scope.
     *
     * DELETE /recurrences/{rec_id}
     *
     * @throws GoPaySdkException
     */
    public function stopRecurrence(string $recId): void
    {
        $rid = $this->requireNonEmpty($recId, 'recId');
        $this->client->delete("/recurrences/{$rid}");
    }

    /**
     * Start a recurrence — triggers the first charge (sets state to STARTED).
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /recurrences/{rec_id}/start
     *
     * @param array<string, mixed>|null $params Optional payment overrides (amount, order_number, callback…).
     *
     * @throws GoPaySdkException
     */
    public function startRecurrence(string $recId, ?array $params = null): PaymentDetails
    {
        $rid = $this->requireNonEmpty($recId, 'recId');

        return $this->client->post("/recurrences/{$rid}/start", $params, PaymentDetails::class);
    }

    /**
     * Create the next payment for a recurrence already in the STARTED state.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /recurrences/{rec_id}/next
     *
     * @param array<string, mixed>|null $params Optional payment overrides (amount, order_number, callback…).
     *
     * @throws GoPaySdkException
     */
    public function recurrenceNext(string $recId, ?array $params = null): PaymentDetails
    {
        $rid = $this->requireNonEmpty($recId, 'recId');

        return $this->client->post("/recurrences/{$rid}/next", $params, PaymentDetails::class);
    }
}

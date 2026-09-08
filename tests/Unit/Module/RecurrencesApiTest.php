<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Generated\Model\RecurrencePayment;
use GoPay\Payments\Generated\Model\RecurrenceState;
use GoPay\Payments\Generated\Model\RecurrenceStopReason;
use GoPay\Payments\Generated\Model\RecurrenceType;
use GoPay\Payments\Module\RecurrencesApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RecurrencesApiTest extends ModuleTestCase
{
    private RecurrencesApi $recurrences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recurrences = new RecurrencesApi($this->http);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function recurrenceDetailsJson(array $overrides = []): array
    {
        return array_merge([
            'id' => '8008013370',
            'type' => 'ON_DEMAND',
            'state' => 'NEW',
            'recurrence_date_to' => '2027-09-04',
            'payment' => [
                'amount' => 1500,
                'currency' => 'CZK',
                'order_number' => '2025010199',
                'customer' => ['email' => 'john.doe@example.com'],
            ],
        ], $overrides);
    }

    /**
     * Argument validation must report `InvalidArgument`, not merely some
     * GoPaySdkException — a regression raising the wrong code would still pass.
     *
     * @param callable(): mixed $call
     */
    private function assertRejectsArgument(callable $call, string $expectedMessage): void
    {
        try {
            $call();
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::InvalidArgument, $e->errorCode);
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // createRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function createRecurrenceThrowsWhenGoidIsEmpty(): void
    {
        $this->assertRejectsArgument(
            fn() => $this->recurrences->createRecurrence('', ['type' => 'ON_DEMAND']),
            'goid must not be empty',
        );
    }

    #[Test]
    public function createRecurrenceReturnsTheRecurrenceInStateNew(): void
    {
        $this->queueJson($this->recurrenceDetailsJson(), 201);

        $result = $this->recurrences->createRecurrence('8761908826', [
            'type' => 'ON_DEMAND',
            'recurrence_date_to' => '2027-09-04',
            'payment' => [
                'amount' => 1500,
                'currency' => 'CZK',
                'order_number' => '2025010199',
                'customer' => ['email' => 'john.doe@example.com'],
            ],
        ]);

        $this->assertInstanceOf(RecurrenceDetails::class, $result);
        $this->assertSame('8008013370', $result->getId());
        // `_NEW`, not `NEW` — `new` is a PHP reserved word, so the generator prefixes
        // the constant. The wire value is still the plain `NEW`.
        $this->assertSame(RecurrenceState::_NEW, $result->getState());
        $this->assertSame(RecurrenceType::ON_DEMAND, $result->getType());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/eshops/8761908826/recurrences', (string) $requests[0]->getUri());
    }

    /**
     * The create body is a discriminated union and the module is pure pass-through,
     * so both variants must reach the wire exactly as written — including `schedule`
     * being present for AUTO and absent for ON_DEMAND, which is the whole shape rule.
     */
    #[Test]
    public function createRecurrenceSendsTheAutoVariantVerbatim(): void
    {
        $this->queueJson($this->recurrenceDetailsJson([
            'type' => 'AUTO',
            'schedule' => ['period' => 'MONTH', 'cycle' => 1],
        ]), 201);

        $this->recurrences->createRecurrence('8761908826', [
            'type' => 'AUTO',
            'schedule' => ['period' => 'MONTH', 'cycle' => 1],
            'recurrence_date_to' => '2027-09-04',
            'payment' => [
                'amount' => 1500,
                'currency' => 'CZK',
                'order_number' => '2025010199',
                'customer' => ['email' => 'john.doe@example.com'],
            ],
        ]);

        /** @var array{type: string, schedule: array{period: string, cycle: int}, recurrence_date_to: string} $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true);
        $this->assertSame('AUTO', $body['type']);
        $this->assertSame('MONTH', $body['schedule']['period']);
        $this->assertSame(1, $body['schedule']['cycle']);
        $this->assertSame('2027-09-04', $body['recurrence_date_to']);
    }

    #[Test]
    public function createRecurrenceSendsTheOnDemandVariantWithoutASchedule(): void
    {
        $this->queueJson($this->recurrenceDetailsJson(), 201);

        $this->recurrences->createRecurrence('8761908826', [
            'type' => 'ON_DEMAND',
            'recurrence_date_to' => '2027-09-04',
            'payment' => ['amount' => 1500],
        ]);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true);
        $this->assertSame('ON_DEMAND', $body['type']);
        $this->assertArrayNotHasKey('schedule', $body);
    }

    /**
     * The two variants are `additionalProperties: false`, so the server rejects a
     * body the SDK happily passed on — an ON_DEMAND recurrence carrying a schedule,
     * a `recurrence_date_to` with a time in it, an unknown field at any depth. The
     * module must surface that as GoPayHttpException, which is what the docblock
     * promises, rather than swallowing it.
     */
    #[Test]
    public function createRecurrenceSurfacesAShapeViolationAsHttpException(): void
    {
        $this->queueJson(['error' => 'BAD_REQUEST', 'message' => 'schedule must not be set'], 400);

        try {
            $this->recurrences->createRecurrence('8761908826', [
                'type' => 'ON_DEMAND',
                'schedule' => ['period' => 'MONTH', 'cycle' => 1],
                'recurrence_date_to' => '2027-09-04',
                'payment' => ['amount' => 1500],
            ]);
            $this->fail('Expected GoPayHttpException for an invalid create body.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(400, $e->status);
        }
    }

    // -------------------------------------------------------------------------
    // recurrenceStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function recurrenceStatusThrowsWhenRecIdIsEmpty(): void
    {
        $this->assertRejectsArgument(
            fn() => $this->recurrences->recurrenceStatus(''),
            'recId must not be empty',
        );
    }

    #[Test]
    public function recurrenceStatusReturnsTheStoredTemplateBeforeAnyPaymentExists(): void
    {
        $this->queueJson($this->recurrenceDetailsJson());

        $result = $this->recurrences->recurrenceStatus('8008013370');

        $this->assertInstanceOf(RecurrenceDetails::class, $result);

        // Before the recurrence is started, `payment` is the template: amount and
        // customer are there, but the payment does not exist yet, so it has no id,
        // no state and no gw_url.
        $payment = $result->getPayment();
        $this->assertInstanceOf(RecurrencePayment::class, $payment);
        $this->assertSame(1500, $payment->getAmount());
        $this->assertNull($payment->getId());
        $this->assertNull($payment->getState());
        $this->assertNull($payment->getGwUrl());

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/8008013370', (string) $requests[0]->getUri());
    }

    #[Test]
    public function recurrenceStatusFillsInThePaymentOnceOneExists(): void
    {
        $this->queueJson($this->recurrenceDetailsJson([
            'state' => 'STARTED',
            'payment' => [
                'id' => '7310142951',
                'state' => 'PAID',
                'amount' => 1500,
                'currency' => 'CZK',
                'order_number' => '2025010199',
                'customer' => ['email' => 'john.doe@example.com'],
                'gw_url' => 'https://gate.gopay.com/gw/123456789',
            ],
        ]));

        $payment = $this->recurrences->recurrenceStatus('8008013370')->getPayment();

        $this->assertInstanceOf(RecurrencePayment::class, $payment);
        $this->assertSame('7310142951', $payment->getId());
        $this->assertSame('PAID', $payment->getState());
        $this->assertSame('https://gate.gopay.com/gw/123456789', $payment->getGwUrl());
    }

    /**
     * Every stop reason the API can report. `stop_reason` is present only in state
     * STOPPED, and the README names the reasons individually, so a value the
     * generated class does not know about would be a silent contract drift.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function stopReasons(): iterable
    {
        yield 'ran past recurrence_date_to' => ['RECURRENCE_EXPIRED', RecurrenceStopReason::RECURRENCE_EXPIRED];
        yield 'stored card expired'         => ['CARD_EXPIRED', RecurrenceStopReason::CARD_EXPIRED];
        yield 'stopped through the API'     => ['CANCELLED_VIA_API', RecurrenceStopReason::CANCELLED_VIA_API];
        yield 'stopped in the backoffice'   => ['CANCELLED_VIA_BACKOFFICE', RecurrenceStopReason::CANCELLED_VIA_BACKOFFICE];
        yield 'stopped by GoPay'            => ['CANCELLED_BY_GOPAY', RecurrenceStopReason::CANCELLED_BY_GOPAY];
        yield 'unknown'                     => ['UNKNOWN', RecurrenceStopReason::UNKNOWN];
    }

    #[Test]
    #[DataProvider('stopReasons')]
    public function recurrenceStatusMapsEveryStopReason(string $wire, string $expected): void
    {
        $this->queueJson($this->recurrenceDetailsJson(['state' => 'STOPPED', 'stop_reason' => $wire]));

        $result = $this->recurrences->recurrenceStatus('8008013370');

        $this->assertSame(RecurrenceState::STOPPED, $result->getState());
        $this->assertSame($expected, $result->getStopReason());
    }

    #[Test]
    public function recurrenceStatusLeavesStopReasonUnsetWhileTheRecurrenceRuns(): void
    {
        $this->queueJson($this->recurrenceDetailsJson(['state' => 'STARTED']));

        $this->assertNull($this->recurrences->recurrenceStatus('8008013370')->getStopReason());
    }

    // -------------------------------------------------------------------------
    // stopRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function stopRecurrenceThrowsWhenRecIdIsEmpty(): void
    {
        $this->assertRejectsArgument(
            fn() => $this->recurrences->stopRecurrence(''),
            'recId must not be empty',
        );
    }

    #[Test]
    public function stopRecurrenceSendsDelete(): void
    {
        $this->queue204();

        $this->recurrences->stopRecurrence('8008013370');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/8008013370', (string) $requests[0]->getUri());
    }

    /**
     * Stopping is not idempotent — the second DELETE answers 409, not 204. Pinned
     * because a caller retrying a timed-out stop needs to tell "already stopped"
     * from a real failure.
     */
    #[Test]
    public function stopRecurrenceSurfacesTheConflictOnASecondStop(): void
    {
        $this->queueJson([
            'error' => 'CONFLICT',
            'message' => 'Recurrence is already stopped. Reason=RECURRENCE_STOPPED',
        ], 409);

        try {
            $this->recurrences->stopRecurrence('8008013370');
            $this->fail('Expected GoPayHttpException for an already stopped recurrence.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    // -------------------------------------------------------------------------
    // startRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function startRecurrenceThrowsWhenRecIdIsEmpty(): void
    {
        $this->assertRejectsArgument(
            fn() => $this->recurrences->startRecurrence(''),
            'recId must not be empty',
        );
    }

    #[Test]
    public function startRecurrenceReturnsThePaymentToPay(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => '7310142951', 'state' => 'CREATED']), 201);

        $result = $this->recurrences->startRecurrence('8008013370');

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('7310142951', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/8008013370/start', (string) $requests[0]->getUri());
    }

    #[Test]
    public function startRecurrenceSendsThePerPaymentOverrides(): void
    {
        $this->queueJson($this->paymentDetailsJson(), 201);

        $this->recurrences->startRecurrence('8008013370', [
            'amount' => 2500,
            'order_number' => '2026-00042',
            'customer' => ['first_name' => 'Jane'],
        ]);

        /** @var array{amount: int, order_number: string, customer: array{first_name: string}} $body */
        $body = json_decode((string) $this->mockClient->getRequests()[0]->getBody(), true);
        $this->assertSame(2500, $body['amount']);
        $this->assertSame('2026-00042', $body['order_number']);
        // `customer` is merged field by field server-side, so a partial one — here
        // without the `email` the shared Customer schema requires — is legitimate
        // and must not be padded out or rejected on the way.
        $this->assertSame(['first_name' => 'Jane'], $body['customer']);
    }

    #[Test]
    public function startRecurrenceSurfacesTheConflictWhileAPaymentIsInProgress(): void
    {
        $this->queueJson([
            'error' => 'CONFLICT',
            'message' => 'Previous payment still in progress',
        ], 409);

        try {
            $this->recurrences->startRecurrence('8008013370');
            $this->fail('Expected GoPayHttpException for a repeated start.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    // -------------------------------------------------------------------------
    // recurrenceNext
    // -------------------------------------------------------------------------

    #[Test]
    public function recurrenceNextThrowsWhenRecIdIsEmpty(): void
    {
        $this->assertRejectsArgument(
            fn() => $this->recurrences->recurrenceNext(''),
            'recId must not be empty',
        );
    }

    #[Test]
    public function recurrenceNextReturnsTheCreatedPayment(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => '7310142952']), 201);

        $result = $this->recurrences->recurrenceNext('8008013370');

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('7310142952', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/8008013370/next', (string) $requests[0]->getUri());
    }

    /**
     * `next` answers 409 for the whole window between `start` and the customer
     * paying the first payment — the recurrence is `REQUESTED`, not `STARTED`, and
     * a caller that chains the two calls hits exactly this.
     */
    #[Test]
    public function recurrenceNextSurfacesTheConflictBeforeTheRecurrenceIsStarted(): void
    {
        $this->queueJson([
            'error' => 'CONFLICT',
            'message' => 'Recurrence has not been started yet',
        ], 409);

        try {
            $this->recurrences->recurrenceNext('8008013370');
            $this->fail('Expected GoPayHttpException while the recurrence is not STARTED.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    /**
     * No overrides means no body — but the request must still say what it is not
     * sending. Dropping the `Content-Type` header along with the body makes the
     * gateway reject the call before it reaches the endpoint (RESTEASY003065,
     * surfaced as `415`), so `recurrenceNext($id)` and `startRecurrence($id)` would
     * fail for exactly the callers who pass no overrides — the documented default.
     * Verified against alpha9: body-less with the header is accepted, body-less
     * without it is a 415.
     *
     * @return iterable<string, array{callable(RecurrencesApi): mixed}>
     */
    public static function bodylessPosts(): iterable
    {
        yield 'start' => [fn(RecurrencesApi $api) => $api->startRecurrence('8008013370')];
        yield 'next'  => [fn(RecurrencesApi $api) => $api->recurrenceNext('8008013370')];
    }

    #[Test]
    #[DataProvider('bodylessPosts')]
    public function bodylessPostStillDeclaresItsContentType(callable $call): void
    {
        $this->queueJson($this->paymentDetailsJson(), 201);

        $call($this->recurrences);

        $request = $this->mockClient->getRequests()[0];
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }
}

<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Module\RecurrencesApi;
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
            'id' => 'rec-001',
            'state' => 'NEW',
            'type' => 'ON_DEMAND',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // createRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function createRecurrenceThrowsWhenGoidIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('goid must not be empty');
        $this->recurrences->createRecurrence('', ['type' => 'ON_DEMAND']);
    }

    #[Test]
    public function createRecurrenceReturnsRecurrenceDetails(): void
    {
        $this->queueJson($this->recurrenceDetailsJson(['id' => 'rec-001']));

        $result = $this->recurrences->createRecurrence('goid-123', ['type' => 'ON_DEMAND']);

        $this->assertInstanceOf(RecurrenceDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringContainsString('/eshops/goid-123/recurrences', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // recurrenceStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function recurrenceStatusThrowsWhenRecIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('recId must not be empty');
        $this->recurrences->recurrenceStatus('');
    }

    #[Test]
    public function recurrenceStatusReturnsRecurrenceDetails(): void
    {
        $this->queueJson($this->recurrenceDetailsJson(['id' => 'rec-001', 'state' => 'NEW']));

        $result = $this->recurrences->recurrenceStatus('rec-001');

        $this->assertInstanceOf(RecurrenceDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/rec-001', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // stopRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function stopRecurrenceThrowsWhenRecIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('recId must not be empty');
        $this->recurrences->stopRecurrence('');
    }

    #[Test]
    public function stopRecurrenceSendsDeleteRequest(): void
    {
        $this->queue204();

        $this->recurrences->stopRecurrence('rec-001');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/rec-001', (string) $requests[0]->getUri());
    }

    #[Test]
    public function stopRecurrenceCompletesWithoutThrowingOnSuccess(): void
    {
        $this->queue204();
        $this->recurrences->stopRecurrence('rec-001');
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // startRecurrence
    // -------------------------------------------------------------------------

    #[Test]
    public function startRecurrenceThrowsWhenRecIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('recId must not be empty');
        $this->recurrences->startRecurrence('');
    }

    #[Test]
    public function startRecurrenceReturnsPaymentDetails(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => 'pay-002']));

        $result = $this->recurrences->startRecurrence('rec-001', ['amount' => 1000]);

        $this->assertInstanceOf(PaymentDetails::class, $result);
        $this->assertSame('pay-002', $result->getId());

        $requests = $this->mockClient->getRequests();
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/rec-001/start', (string) $requests[0]->getUri());
    }

    #[Test]
    public function startRecurrenceWorksWithNullParams(): void
    {
        $this->queueJson($this->paymentDetailsJson());

        $result = $this->recurrences->startRecurrence('rec-001', null);

        $this->assertInstanceOf(PaymentDetails::class, $result);
    }

    // -------------------------------------------------------------------------
    // recurrenceNext
    // -------------------------------------------------------------------------

    #[Test]
    public function recurrenceNextThrowsWhenRecIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('recId must not be empty');
        $this->recurrences->recurrenceNext('');
    }

    #[Test]
    public function recurrenceNextReturnsPaymentDetails(): void
    {
        $this->queueJson($this->paymentDetailsJson(['id' => 'pay-003', 'order_number' => 'ORDER-003']));

        $result = $this->recurrences->recurrenceNext('rec-001', ['amount' => 500]);

        $this->assertInstanceOf(PaymentDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/recurrences/rec-001/next', (string) $requests[0]->getUri());
    }

    #[Test]
    public function recurrenceNextWorksWithNullParams(): void
    {
        $this->queueJson($this->paymentDetailsJson());

        $result = $this->recurrences->recurrenceNext('rec-001', null);

        $this->assertInstanceOf(PaymentDetails::class, $result);
    }
}

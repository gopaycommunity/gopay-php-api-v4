<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\Module\RefundsApi;
use PHPUnit\Framework\Attributes\Test;

final class RefundsApiTest extends ModuleTestCase
{
    private RefundsApi $refunds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refunds = new RefundsApi($this->http);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function refundDetailsJson(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ref-001',
            'state' => 'REQUESTED',
            'amount' => 500,
            'currency' => 'CZK',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // refundPayment
    // -------------------------------------------------------------------------

    #[Test]
    public function refundPaymentThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->refunds->refundPayment('', ['amount' => 500]);
    }

    #[Test]
    public function refundPaymentReturnsRefundDetails(): void
    {
        $this->queueJson($this->refundDetailsJson(['id' => 'ref-001']));

        $result = $this->refunds->refundPayment('pay-001', ['amount' => 500]);

        $this->assertInstanceOf(RefundDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/refunds', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // listRefunds
    // -------------------------------------------------------------------------

    #[Test]
    public function listRefundsThrowsWhenPaymentIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');
        $this->refunds->listRefunds('');
    }

    #[Test]
    public function listRefundsReturnsListOfRefundDetails(): void
    {
        $this->queueJson([
            $this->refundDetailsJson(['id' => 'ref-001']),
            $this->refundDetailsJson(['id' => 'ref-002', 'amount' => 200]),
        ]);

        $result = $this->refunds->listRefunds('pay-001');

        $this->assertCount(2, $result);
        $this->assertInstanceOf(RefundDetails::class, $result[0]);
        $this->assertInstanceOf(RefundDetails::class, $result[1]);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/payments/pay-001/refunds', (string) $requests[0]->getUri());
    }

    #[Test]
    public function listRefundsReturnsEmptyListWhenNoRefunds(): void
    {
        $this->queueJson([]);

        $result = $this->refunds->listRefunds('pay-001');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // getRefund
    // -------------------------------------------------------------------------

    #[Test]
    public function getRefundThrowsWhenRefundIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('refundId must not be empty');
        $this->refunds->getRefund('');
    }

    #[Test]
    public function getRefundReturnsRefundDetails(): void
    {
        $this->queueJson($this->refundDetailsJson(['id' => 'ref-123']));

        $result = $this->refunds->getRefund('ref-123');

        $this->assertInstanceOf(RefundDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/refunds/ref-123', (string) $requests[0]->getUri());
    }
}

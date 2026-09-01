<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\LinkStopReason;
use GoPay\Payments\Module\LinksApi;
use PHPUnit\Framework\Attributes\Test;

final class LinksApiTest extends ModuleTestCase
{
    private LinksApi $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new LinksApi($this->http);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function linkDetailsJson(array $overrides = []): array
    {
        return array_merge([
            'id' => '3405871122',
            'url' => 'https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t',
            'active' => true,
            'reusable' => true,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // createPaymentLink
    // -------------------------------------------------------------------------

    #[Test]
    public function createPaymentLinkThrowsWhenGoidIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('goid must not be empty');
        $this->links->createPaymentLink('', ['payment' => []]);
    }

    #[Test]
    public function createPaymentLinkReturnsLinkDetails(): void
    {
        $this->queueJson($this->linkDetailsJson(), 201);

        $result = $this->links->createPaymentLink('8398119642', [
            'expires_in' => 3600,
            'reusable' => true,
            'payment' => [
                'amount' => 15000,
                'currency' => 'CZK',
                'order_number' => '2026-00042',
                'customer' => ['email' => 'payer@example.com'],
                'callback' => [
                    'notification_url' => 'https://eshop.example.com/gopay/notify',
                    'return_url' => 'https://eshop.example.com/gopay/return',
                ],
            ],
        ]);

        $this->assertInstanceOf(LinkDetails::class, $result);
        $this->assertSame('3405871122', $result->getId());
        $this->assertSame('https://gate.gopay.com/gp-gw/l/Xk8mQ2pR7t', $result->getUrl());

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // linkStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function linkStatusThrowsWhenGoidIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('goid must not be empty');
        $this->links->linkStatus('', '3405871122');
    }

    #[Test]
    public function linkStatusThrowsWhenLinkIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('linkId must not be empty');
        $this->links->linkStatus('8398119642', '');
    }

    #[Test]
    public function linkStatusRequestsTheLinkUnderItsEshop(): void
    {
        $this->queueJson($this->linkDetailsJson());

        $result = $this->links->linkStatus('8398119642', '3405871122');

        $this->assertInstanceOf(LinkDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links/3405871122', (string) $requests[0]->getUri());
    }

    #[Test]
    public function linkStatusExposesStopReasonOnAnInactiveLink(): void
    {
        $this->queueJson($this->linkDetailsJson([
            'active' => false,
            'reusable' => false,
            'stop_reason' => 'USED',
        ]));

        $result = $this->links->linkStatus('8398119642', '3405871122');

        $this->assertFalse($result->getActive());
        $this->assertSame(LinkStopReason::USED, $result->getStopReason());
    }

    #[Test]
    public function linkStatusLeavesStopReasonUnsetWhileTheLinkIsActive(): void
    {
        $this->queueJson($this->linkDetailsJson());

        $result = $this->links->linkStatus('8398119642', '3405871122');

        $this->assertTrue($result->getActive());
        $this->assertNull($result->getStopReason());
    }

    // -------------------------------------------------------------------------
    // disableLink
    // -------------------------------------------------------------------------

    #[Test]
    public function disableLinkThrowsWhenGoidIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('goid must not be empty');
        $this->links->disableLink('', '3405871122');
    }

    #[Test]
    public function disableLinkThrowsWhenLinkIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('linkId must not be empty');
        $this->links->disableLink('8398119642', '');
    }

    #[Test]
    public function disableLinkSendsDeleteRequest(): void
    {
        $this->queue204();

        $this->links->disableLink('8398119642', '3405871122');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/eshops/8398119642/links/3405871122', (string) $requests[0]->getUri());
    }

    #[Test]
    public function disableLinkCompletesWithoutThrowingOnSuccess(): void
    {
        $this->queue204();
        $this->links->disableLink('8398119642', '3405871122');
        $this->addToAssertionCount(1);
    }
}

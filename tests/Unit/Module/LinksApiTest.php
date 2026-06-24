<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
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
            'id' => 'link-001',
            'url' => 'https://pay.example.com/link/link-001',
            'active' => true,
            'reusable' => false,
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
        $this->links->createPaymentLink('', ['amount' => 1000]);
    }

    #[Test]
    public function createPaymentLinkReturnsLinkDetails(): void
    {
        $this->queueJson($this->linkDetailsJson(['id' => 'link-001']));

        $result = $this->links->createPaymentLink('goid-123', ['amount' => 1000, 'currency' => 'CZK']);

        $this->assertInstanceOf(LinkDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertStringContainsString('/eshops/goid-123/links', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // linkStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function linkStatusThrowsWhenLinkIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('linkId must not be empty');
        $this->links->linkStatus('');
    }

    #[Test]
    public function linkStatusReturnsLinkDetails(): void
    {
        $this->queueJson($this->linkDetailsJson(['id' => 'link-001', 'active' => true]));

        $result = $this->links->linkStatus('link-001');

        $this->assertInstanceOf(LinkDetails::class, $result);

        $requests = $this->mockClient->getRequests();
        $this->assertSame('GET', $requests[0]->getMethod());
        $this->assertStringEndsWith('/links/link-001', (string) $requests[0]->getUri());
    }

    // -------------------------------------------------------------------------
    // disableLink
    // -------------------------------------------------------------------------

    #[Test]
    public function disableLinkThrowsWhenLinkIdIsEmpty(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('linkId must not be empty');
        $this->links->disableLink('');
    }

    #[Test]
    public function disableLinkSendsDeleteRequest(): void
    {
        $this->queue204();

        $this->links->disableLink('link-001');

        $requests = $this->mockClient->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('DELETE', $requests[0]->getMethod());
        $this->assertStringEndsWith('/links/link-001', (string) $requests[0]->getUri());
    }

    #[Test]
    public function disableLinkCompletesWithoutThrowingOnSuccess(): void
    {
        $this->queue204();
        $this->links->disableLink('link-001');
        $this->addToAssertionCount(1);
    }
}

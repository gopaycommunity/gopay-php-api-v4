<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Module\CardsApi;
use GoPay\Payments\Module\LinksApi;
use GoPay\Payments\Module\PaymentsApi;
use GoPay\Payments\Module\RefundsApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ids are interpolated into request paths, and the PSR-7 URI does not escape
 * `/`, `?`, `#` or `.` on our behalf. Raw, a traversal-shaped id walks out of
 * its own endpoint and a `?`-bearing one appends a query string — both silently
 * addressing something the caller never asked for.
 *
 * Every module that puts an id in a path is covered here, because this is the
 * kind of guarantee that quietly regresses when a new endpoint is added by
 * copying an existing method.
 */
final class PathSegmentEscapingTest extends ModuleTestCase
{
    /**
     * A traversal attempt must stay inside its own segment, and the encoded
     * form must not reintroduce a slash.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hostileIds(): iterable
    {
        yield 'path traversal'   => ['../../payments/pay-1', '..%2F..%2Fpayments%2Fpay-1'];
        yield 'query injection'  => ['1?format=svg', '1%3Fformat%3Dsvg'];
        yield 'fragment'         => ['1#frag', '1%23frag'];
        yield 'trailing slash'   => ['1/', '1%2F'];
    }

    #[Test]
    #[DataProvider('hostileIds')]
    public function paymentsApiEscapesThePaymentId(string $raw, string $encoded): void
    {
        $this->queueJson(['id' => 'pay-1']);
        (new PaymentsApi($this->http))->getPaymentStatus($raw);

        $uri = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringEndsWith("/payments/{$encoded}", $uri);
    }

    #[Test]
    #[DataProvider('hostileIds')]
    public function refundsApiEscapesTheRefundId(string $raw, string $encoded): void
    {
        $this->queueJson(['id' => 'ref-1', 'state' => 'SUCCESS', 'amount' => 1, 'currency' => 'CZK', 'created_at' => '2026-01-01T00:00:00Z']);
        (new RefundsApi($this->http))->getRefund($raw);

        $uri = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringEndsWith("/refunds/{$encoded}", $uri);
    }

    #[Test]
    #[DataProvider('hostileIds')]
    public function cardsApiEscapesTheCardId(string $raw, string $encoded): void
    {
        $this->queue204();
        (new CardsApi($this->http))->deleteCard($raw);

        $uri = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringEndsWith("/cards/tokens/{$encoded}", $uri);
    }

    #[Test]
    #[DataProvider('hostileIds')]
    public function linksApiEscapesBothSegments(string $raw, string $encoded): void
    {
        $this->queue204();
        (new LinksApi($this->http))->disableLink($raw, $raw);

        $uri = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringEndsWith("/eshops/{$encoded}/links/{$encoded}", $uri);
    }
}

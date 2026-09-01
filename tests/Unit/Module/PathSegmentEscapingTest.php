<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPaySdkException;
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
        // `.` is unreserved in RFC 3986, so encoding leaves a run of dots alone.
        // Three of them is an ordinary segment and must survive as itself — only
        // the two dot segments proper are rejected, see dotSegmentsAreRejected().
        yield 'three dots'       => ['...', '...'];
    }

    /**
     * Exactly `.` and `..` are dot segments. `rawurlencode()` returns them
     * unchanged — they are unreserved — so a bare `..` escapes its endpoint with
     * no slash of its own: anything normalising `/payments/..` resolves it to
     * `/`. Encoding cannot fix that, so they are refused outright.
     *
     * @return iterable<string, array{string}>
     */
    public static function dotSegments(): iterable
    {
        yield 'single dot' => ['.'];
        yield 'double dot' => ['..'];
    }

    #[Test]
    #[DataProvider('dotSegments')]
    public function dotSegmentsAreRejected(string $raw): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be "." or ".."');

        (new PaymentsApi($this->http))->getPaymentStatus($raw);
    }

    #[Test]
    #[DataProvider('dotSegments')]
    public function dotSegmentsAreRejectedOnEveryModule(string $raw): void
    {
        $this->expectException(GoPaySdkException::class);

        (new LinksApi($this->http))->disableLink('8398119642', $raw);
    }

    /**
     * `requireNonEmpty()` trims only to decide emptiness, so a whitespace-only id
     * is refused — but a padded one is *not* trimmed, it is encoded. Pinning both
     * halves, because the asymmetry is easy to "tidy up" into silent trimming.
     */
    #[Test]
    public function whitespaceOnlySegmentIsRejected(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('paymentId must not be empty');

        (new PaymentsApi($this->http))->getPaymentStatus("  \t ");
    }

    #[Test]
    public function paddedSegmentIsEncodedRatherThanTrimmed(): void
    {
        $this->queueJson(['id' => 'pay-1']);
        (new PaymentsApi($this->http))->getPaymentStatus(' 300000001 ');

        $uri = (string) $this->mockClient->getRequests()[0]->getUri();
        $this->assertStringEndsWith('/payments/%20300000001%20', $uri);
    }

    #[Test]
    public function noRequestIsSentWhenTheSegmentIsRejected(): void
    {
        try {
            (new RefundsApi($this->http))->getRefund('..');
        } catch (GoPaySdkException) {
            // expected
        }

        $this->assertCount(0, $this->mockClient->getRequests());
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

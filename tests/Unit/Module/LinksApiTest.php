<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\LinkStopReason;
use GoPay\Payments\Module\LinksApi;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // createPaymentLink is pure pass-through — pin that the params reach the wire
        // verbatim, unwrapped.
        /** @var array{expires_in: int, reusable: bool, payment: array{amount: int, order_number: string, customer: array{email: string}}} $body */
        $body = json_decode((string) $requests[0]->getBody(), true);
        $this->assertSame(3600, $body['expires_in']);
        $this->assertTrue($body['reusable']);
        $this->assertSame(15000, $body['payment']['amount']);
        $this->assertSame('2026-00042', $body['payment']['order_number']);
        $this->assertSame('payer@example.com', $body['payment']['customer']['email']);
    }

    /**
     * Validation runs before anything is stored and every failure is a 400 — an
     * unsupported currency for the eshop, an over-long field, a `=` in an
     * additional_param name. The module surfaces it as GoPayHttpException, which
     * is what the docblock now promises.
     */
    #[Test]
    public function createPaymentLinkSurfacesValidationFailure(): void
    {
        $this->queueJson([
            'error' => 'BAD_REQUEST',
            'message' => 'Value of the property payment.customer.email is missing or is not valid.',
        ], 400);

        try {
            $this->links->createPaymentLink('8398119642', ['payment' => ['amount' => 15000]]);
            $this->fail('Expected GoPayHttpException for an invalid link payload.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(400, $e->status);
        }
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
    public function linkStatusReportsAnInactiveLink(): void
    {
        $this->queueJson($this->linkDetailsJson(['active' => false, 'reusable' => false, 'stop_reason' => 'USED']));

        $result = $this->links->linkStatus('8398119642', '3405871122');

        $this->assertFalse($result->getActive());
    }

    /**
     * All three reasons the API can report, since the module docblock and the
     * README name them individually as promises to the caller.
     *
     * `LinkStopReason` is a generated class of string constants, not a PHP enum,
     * so both sides of the assertion are plain strings.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function stopReasons(): iterable
    {
        yield 'disabled by the merchant' => ['FROM_API', LinkStopReason::FROM_API];
        yield 'one-shot link consumed'   => ['USED', LinkStopReason::USED];
        yield 'past its expiry'          => ['EXPIRED', LinkStopReason::EXPIRED];
    }

    #[Test]
    #[DataProvider('stopReasons')]
    public function linkStatusMapsEveryStopReason(string $wire, string $expected): void
    {
        $this->queueJson($this->linkDetailsJson(['active' => false, 'stop_reason' => $wire]));

        $result = $this->links->linkStatus('8398119642', '3405871122');

        $this->assertSame($expected, $result->getStopReason());
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
    public function disableLinkSurfacesConflictWhenTheLinkIsAlreadyInactive(): void
    {
        // A link that is already disabled, expired, or a consumed one-shot answers 409.
        $this->queueJson(['error' => 'CONFLICT', 'message' => 'Link is not active'], 409);

        try {
            $this->links->disableLink('8398119642', '3405871122');
            $this->fail('Expected GoPayHttpException for an already-inactive link.');
        } catch (GoPayHttpException $e) {
            $this->assertSame(409, $e->status);
        }
    }
}

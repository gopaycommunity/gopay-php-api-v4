<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\E2E;

use GoPay\Payments\Event\UnknownEvent;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Generated\Model\PaymentCanceledEvent;

/**
 * Events and webhooks against a live gateway (alpha9 in CI). Delivery, retries, policies and
 * every event type are OLAF's to cover; this checks that the SDK reads what the gateway writes.
 *
 * Webhook delivery is checked only where GOPAY_E2E_WEBHOOK_INSPECTION_URL names demo-site's
 * receiver (GPOMA-2797), e.g. https://demo.alpha9.dev.gopay.com/api/v1/test/webhooks.
 *
 * Run: phpunit --testsuite E2E
 */
#[\PHPUnit\Framework\Attributes\Group('e2e')]
class EventsE2ETest extends E2ETestCase
{
    private const SCOPE = 'payment:write payment:read event:read shared:read';

    private static ?string $paymentId = null;
    private static ?PaymentCanceledEvent $canceled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sdk->authenticate(
            self::requireEnv('GOPAY_PAYMENTS_V4_CLIENT_ID'),
            self::requireEnv('GOPAY_PAYMENTS_V4_CLIENT_SECRET'),
            self::SCOPE,
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function loads_the_webhook_signing_keys(): void
    {
        $keys = $this->sdk->getWebhookPublicKeys()->getKeys();

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertSame('RS256', $key->getAlg());
            $this->assertNotSame('', $key->getKid());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lists_the_cancel_of_a_payment_as_payments_canceled(): void
    {
        $canceled = $this->canceledEvent();

        $this->assertSame(self::$paymentId, $canceled->getData()->getPaymentId());
        $this->assertSame('payments.canceled', $canceled->getType());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returns_the_same_event_from_get_event_as_from_the_list(): void
    {
        $canceled = $this->canceledEvent();

        $event = $this->sdk->getEvent($this->goid, (string) $canceled->getId());

        $this->assertInstanceOf(PaymentCanceledEvent::class, $event);
        $this->assertEquals($canceled, $event);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function pages_across_a_page_boundary_newest_first_without_repeating_an_event(): void
    {
        $this->canceledEvent(); // at least one event exists
        $first = $this->sdk->listEvents($this->goid, ['max_items' => 2]);
        $this->assertNotNull($first->getNextPageUrl(), 'the eshop needs more than two events');

        $seen = [];
        foreach ($this->sdk->iterateEvents($this->goid, ['max_items' => 2]) as $event) {
            if ($event instanceof UnknownEvent) {
                $this->fail('the gateway sent a type this SDK does not know: ' . $event->getType());
            }
            $seen[] = $event;
            if (count($seen) === 5) {
                break;
            }
        }

        $this->assertCount(5, array_unique(array_map(static fn($e) => (string) $e->getId(), $seen)));
        $times = array_map(static fn($e) => $e->getOccurredAt()->format('U.u'), $seen);
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function surfaces_a_400_as_go_pay_http_exception_with_its_ids(): void
    {
        try {
            $this->sdk->listEvents($this->goid, ['max_items' => 0]);
            $this->fail('expected a GoPayHttpException');
        } catch (GoPayHttpException $e) {
            $this->assertSame(400, $e->status);
            $this->assertNotNull($e->traceId);
            $this->assertNotNull($e->transactionId);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function surfaces_an_unknown_event_as_a_404(): void
    {
        try {
            $this->sdk->getEvent($this->goid, '01AAAAAAAAAAAAAAAAAAAAAAAA');
            $this->fail('expected a GoPayHttpException');
        } catch (GoPayHttpException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function delivers_the_event_signed_and_verify_webhook_accepts_it_against_the_live_keys(): void
    {
        $inspectionUrl = self::env('GOPAY_E2E_WEBHOOK_INSPECTION_URL');
        if ($inspectionUrl === null || $inspectionUrl === '') {
            // Skipped, not passed: absent, it would read as a delivery that was checked.
            self::markTestSkipped('Webhook delivery not checked: GOPAY_E2E_WEBHOOK_INSPECTION_URL is not set.');
        }
        $canceled = $this->canceledEvent();

        $stored = $this->waitFor('the payments.canceled webhook at the receiver', function () use ($inspectionUrl): ?\stdClass {
            $response = (new \GuzzleHttp\Client(['timeout' => 10, 'http_errors' => true]))
                ->get($inspectionUrl, ['query' => ['payment_id' => self::$paymentId]]);
            $body = json_decode((string) $response->getBody());
            foreach ($body instanceof \stdClass && is_array($body->webhooks ?? null) ? $body->webhooks : [] as $webhook) {
                if ($webhook instanceof \stdClass && ($webhook->type ?? null) === 'payments.canceled') {
                    return $webhook;
                }
            }

            return null;
        }, 30);

        $this->assertSame('GoPay-Webhooks/1.0', $stored->userAgent ?? null);
        $this->assertIsString($stored->bodyBase64 ?? null);
        $this->assertIsString($stored->signature ?? null);
        $event = $this->sdk->verifyWebhook((string) base64_decode($stored->bodyBase64, true), $stored->signature);
        $this->assertEquals($canceled, $event);
    }

    // -------------------------------------------------------------------------

    /** A payment created and cancelled once for the class; its event is recorded asynchronously. */
    private function canceledEvent(): PaymentCanceledEvent
    {
        if (self::$canceled !== null) {
            return self::$canceled;
        }
        $payment = $this->sdk->createPayment($this->goid, [
            'amount'       => 100,
            'currency'     => 'CZK',
            'order_number' => 'e2e-events-' . bin2hex(random_bytes(4)),
            'customer'     => ['email' => 'e2e@example.com'],
            'callback'     => [
                'notification_url' => 'https://example.com/notify',
                'return_url'       => 'https://example.com/return',
            ],
        ]);
        self::$paymentId = (string) $payment->getId();
        $this->sdk->cancelPayment(self::$paymentId);

        $canceled = $this->waitFor('payments.canceled in listEvents', function (): ?PaymentCanceledEvent {
            $page = $this->sdk->listEvents($this->goid, ['related_type' => 'payments', 'related_id' => self::$paymentId]);
            foreach ($page->getItems() as $event) {
                if ($event instanceof PaymentCanceledEvent) {
                    return $event;
                }
            }

            return null;
        }, 15);

        return self::$canceled = $canceled;
    }

    /**
     * @template T of object
     *
     * @param callable(): (T|null) $probe
     *
     * @return T
     */
    private function waitFor(string $what, callable $probe, int $timeoutSeconds): object
    {
        $deadline = microtime(true) + $timeoutSeconds;
        for (;;) {
            $found = $probe();
            if ($found !== null) {
                return $found;
            }
            if (microtime(true) > $deadline) {
                $this->fail("{$what} did not appear within {$timeoutSeconds} s");
            }
            usleep(500_000);
        }
    }
}

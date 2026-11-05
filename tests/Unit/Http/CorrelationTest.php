<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\GoPayClient;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Http\RequestOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CorrelationTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const TOKEN = '{"access_token":"tok","expires_in":3600,"token_type":"bearer"}';

    private MockClient $mockClient;
    private HttpFactory $factory;

    /** @var list<\Throwable> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->mockClient = new MockClient();
        $this->factory = new HttpFactory();
    }

    // -------------------------------------------------------------------------
    // User-Agent
    // -------------------------------------------------------------------------

    #[Test]
    public function every_request_says_it_comes_from_this_sdk_and_its_version(): void
    {
        $sdk = $this->sdk();
        $this->mockClient->addResponse(new Response(200, [], self::TOKEN));
        $this->mockClient->addResponse(self::payment());

        $sdk->authenticate('id', 'secret', 'payment:read');
        $sdk->getPaymentStatus('pay-1');

        [$token, $api] = $this->requests();
        $this->assertMatchesRegularExpression('/^GoPay PHP v4 \S+$/', $token->getHeaderLine('User-Agent'));
        $this->assertSame($token->getHeaderLine('User-Agent'), $api->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function a_configured_suffix_is_appended_not_substituted(): void
    {
        $sdk = $this->sdk(new Config(baseUrl: 'https://mock.example.com', userAgentSuffix: '  MyShop/2.1 (WooCommerce 9) '));
        $this->mockClient->addResponse(new Response(200, [], self::TOKEN));

        $sdk->authenticate('id', 'secret', 'payment:read');

        $this->assertMatchesRegularExpression('#^GoPay PHP v4 \S+ MyShop/2\.1 \(WooCommerce 9\)$#', $this->requests()[0]->getHeaderLine('User-Agent'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSuffixes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'newline' => ["shop\r\nX-Injected: 1"];
        yield 'not ascii' => ['Obchod Žluťoučký'];
        yield 'too long' => [str_repeat('a', 201)];
    }

    #[Test]
    #[DataProvider('badSuffixes')]
    public function a_suffix_that_is_not_printable_ascii_is_refused_where_it_is_configured(string $suffix): void
    {
        try {
            new Config(userAgentSuffix: $suffix);
            $this->fail('expected a GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::InvalidConfig, $e->errorCode);
        }
    }

    #[Test]
    public function per_request_headers_cannot_replace_the_sdks_own(): void
    {
        $http = new HttpClient(new Config(baseUrl: 'https://mock.example.com'), $this->mockClient, $this->factory, $this->factory);
        $http->getTokenStore()->setToken('tok', 3600);
        $this->mockClient->addResponse(self::payment());

        $http->get('/eshops/1/links/l1', PaymentDetails::class, new RequestOptions(headers: [
            'User-Agent'                 => 'spoofed',
            'x-gopay-trace-id'           => 'mine',
            'X-GoPay-Transaction-Id'     => 'mine',
            'X-GoPay-Payment-Session-Id' => 'pay-999',
            'X-Custom'                   => 'kept',
        ]));

        $request = $this->requests()[0];
        $this->assertStringStartsWith('GoPay PHP v4 ', $request->getHeaderLine('User-Agent'));
        $this->assertMatchesRegularExpression(self::UUID_V4, $request->getHeaderLine('X-GoPay-Trace-Id'));
        $this->assertMatchesRegularExpression(self::UUID_V4, $request->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertFalse($request->hasHeader('X-GoPay-Payment-Session-Id'));
        $this->assertSame('kept', $request->getHeaderLine('X-Custom'));
    }

    // -------------------------------------------------------------------------
    // Trace and transaction ids
    // -------------------------------------------------------------------------

    #[Test]
    public function a_call_shares_one_transaction_and_each_request_has_its_own_trace(): void
    {
        $sdk = $this->sdk();
        // A token about to expire: the next call refreshes it first, in the same transaction.
        $this->mockClient->addResponse(new Response(200, [], '{"access_token":"old","expires_in":10,"token_type":"bearer"}'));
        $sdk->authenticate('id', 'secret', 'payment:read');
        $this->mockClient->addResponse(new Response(200, [], self::TOKEN));
        $this->mockClient->addResponse(self::payment());

        $sdk->getPaymentStatus('pay-1');

        [$first, $refresh, $api] = $this->requests();
        $this->assertSame('/oauth2/token', $refresh->getUri()->getPath());
        $this->assertSame($refresh->getHeaderLine('X-GoPay-Transaction-Id'), $api->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertNotSame($first->getHeaderLine('X-GoPay-Transaction-Id'), $api->getHeaderLine('X-GoPay-Transaction-Id'));
        $traces = array_map(static fn(RequestInterface $r) => $r->getHeaderLine('X-GoPay-Trace-Id'), [$first, $refresh, $api]);
        $this->assertCount(3, array_unique($traces));
        foreach ([...$traces, $api->getHeaderLine('X-GoPay-Transaction-Id')] as $id) {
            $this->assertMatchesRegularExpression(self::UUID_V4, $id);
        }
    }

    #[Test]
    public function the_401_retry_keeps_the_trace_id_of_the_request_it_repeats(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(401, [], '{}'));
        $this->mockClient->addResponse(new Response(200, [], self::TOKEN));
        $this->mockClient->addResponse(self::payment());

        $sdk->getPaymentStatus('pay-1');

        [, $rejected, $refresh, $retry] = $this->requests();
        $this->assertSame($rejected->getHeaderLine('X-GoPay-Trace-Id'), $retry->getHeaderLine('X-GoPay-Trace-Id'));
        $this->assertNotSame($rejected->getHeaderLine('X-GoPay-Trace-Id'), $refresh->getHeaderLine('X-GoPay-Trace-Id'));
        $this->assertCount(1, array_unique(array_map(
            static fn(RequestInterface $r) => $r->getHeaderLine('X-GoPay-Transaction-Id'),
            [$rejected, $refresh, $retry],
        )));
    }

    #[Test]
    public function the_polls_of_an_await_method_share_its_transaction(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(200, [], '{"id":"c1","state":"PROCESSING"}'));
        $this->mockClient->addResponse(new Response(200, [], '{"id":"c1","state":"SUCCEEDED"}'));

        $sdk->awaitChargeState('pay-1', timeoutSeconds: 30, pollIntervalMs: 1);

        [, $poll1, $poll2] = $this->requests();
        $this->assertSame($poll1->getHeaderLine('X-GoPay-Transaction-Id'), $poll2->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertNotSame($poll1->getHeaderLine('X-GoPay-Trace-Id'), $poll2->getHeaderLine('X-GoPay-Trace-Id'));
    }

    #[Test]
    public function separate_calls_are_separate_transactions(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());
        $this->mockClient->addResponse(self::payment());

        $sdk->getPaymentStatus('pay-1');
        $sdk->getPaymentStatus('pay-1');

        [, $a, $b] = $this->requests();
        $this->assertNotSame($a->getHeaderLine('X-GoPay-Transaction-Id'), $b->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    #[Test]
    public function with_transaction_id_sends_the_merchants_own_id_on_every_call_inside(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());
        $this->mockClient->addResponse(self::payment());
        $this->mockClient->addResponse(self::payment());

        $result = $sdk->withTransactionId('order_42.retry-1', static function () use ($sdk): string {
            $sdk->getPaymentStatus('pay-1');
            $sdk->getPaymentStatus('pay-2');

            return 'done';
        });
        $sdk->getPaymentStatus('pay-3');

        $this->assertSame('done', $result);
        [, $a, $b, $after] = $this->requests();
        $this->assertSame('order_42.retry-1', $a->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertSame('order_42.retry-1', $b->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertMatchesRegularExpression(self::UUID_V4, $after->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    #[Test]
    public function with_transaction_id_restores_the_outer_id_after_a_nested_one_throws(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());

        $sdk->withTransactionId('outer', static function () use ($sdk): void {
            try {
                $sdk->withTransactionId('inner', static fn() => throw new \RuntimeException('boom'));
            } catch (\RuntimeException) {
            }
            $sdk->getPaymentStatus('pay-1');
        });

        $this->assertSame('outer', $this->requests()[1]->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    #[Test]
    public function an_inner_with_transaction_id_wins_over_the_outer_one(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());
        $this->mockClient->addResponse(self::payment());

        $sdk->withTransactionId('outer', static function () use ($sdk): void {
            $sdk->withTransactionId('inner', static fn() => $sdk->getPaymentStatus('pay-1'));
            $sdk->getPaymentStatus('pay-2');
        });

        [, $inner, $outer] = $this->requests();
        $this->assertSame('inner', $inner->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertSame('outer', $outer->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTransactionIds(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['order/42'];
        yield 'space' => ['order 42'];
        yield 'newline' => ["order\n42"];
        yield 'trailing newline' => ["order42\n"];
        yield '65 characters' => [str_repeat('a', 65)];
    }

    #[Test]
    #[DataProvider('badTransactionIds')]
    public function an_id_the_gateway_would_drop_is_an_sdk_error_not_silently_replaced(string $transactionId): void
    {
        $sdk = $this->authenticated();
        $ran = false;

        try {
            $sdk->withTransactionId($transactionId, static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('expected a GoPaySdkException');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::InvalidArgument, $e->errorCode);
            $this->assertContains($e, $this->seen, 'onError sees it');
        }
        $this->assertFalse($ran);
    }

    #[Test]
    public function a_64_character_id_is_accepted(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());
        $id = str_repeat('Az09._-', 9) . 'x';

        $sdk->withTransactionId($id, static fn() => $sdk->getPaymentStatus('pay-1'));

        $this->assertSame($id, $this->requests()[1]->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    #[Test]
    public function the_pages_of_iterate_events_share_the_transaction_it_was_called_in(): void
    {
        $sdk = $this->authenticated();
        $event = '{"id":"e%d","type":"cards.deleted","occurred_at":"2026-10-27T14:37:27Z","data":{"card_id":"k"}}';
        $this->mockClient->addResponse(new Response(200, [], sprintf('{"items":[%s],"next_page_url":"/x/eshops/1/events?prev_item=e2"}', sprintf($event, 2))));
        $this->mockClient->addResponse(new Response(200, [], sprintf('{"items":[%s]}', sprintf($event, 1))));

        $events = $sdk->withTransactionId('sync-run-7', static function () use ($sdk): \Generator {
            return $sdk->iterateEvents('1');
        });
        // Consumed after withTransactionId has returned: each step resumes in its transaction.
        $ids = [];
        foreach ($events as $e) {
            $ids[] = $e->getId();
        }

        $this->assertSame(['e2', 'e1'], $ids);
        [, $page1, $page2] = $this->requests();
        $this->assertSame('sync-run-7', $page1->getHeaderLine('X-GoPay-Transaction-Id'));
        $this->assertSame('sync-run-7', $page2->getHeaderLine('X-GoPay-Transaction-Id'));
    }

    // -------------------------------------------------------------------------
    // Payment session id
    // -------------------------------------------------------------------------

    #[Test]
    public function requests_about_a_payment_name_it_and_others_do_not(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(200, [], '{"id":"c1","state":"SUCCEEDED"}'));
        $this->mockClient->addResponse(self::payment());
        $this->mockClient->addResponse(new Response(200, [], '{"id":"l1","url":"https://x","active":true}'));

        $sdk->getChargeState('pay/1');
        $sdk->getPaymentStatus('7639814693');
        $sdk->linkStatus('8123456789', 'l1');

        [$token, $charge, $payment, $link] = $this->requests();
        $this->assertFalse($token->hasHeader('X-GoPay-Payment-Session-Id'));
        $this->assertSame('pay%2F1', $charge->getHeaderLine('X-GoPay-Payment-Session-Id'));
        $this->assertSame('7639814693', $payment->getHeaderLine('X-GoPay-Payment-Session-Id'));
        $this->assertFalse($link->hasHeader('X-GoPay-Payment-Session-Id'));
    }

    // -------------------------------------------------------------------------
    // Ids on errors
    // -------------------------------------------------------------------------

    #[Test]
    public function an_http_error_carries_the_ids_of_its_request_before_on_error_sees_it(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(500, [], '{"errors":[]}'));
        $traceSeenByOnError = null;
        $this->onSeen = static function (\Throwable $e) use (&$traceSeenByOnError): void {
            $traceSeenByOnError = $e instanceof GoPayHttpException ? $e->traceId : null;
        };

        $e = $this->catchHttp(fn() => $sdk->getPaymentStatus('pay-1'));

        $request = $this->requests()[1];
        $this->assertSame($request->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
        $this->assertSame($request->getHeaderLine('X-GoPay-Transaction-Id'), $e->transactionId);
        $this->assertSame($traceSeenByOnError, $e->traceId);
    }

    #[Test]
    public function a_network_error_or_timeout_carries_the_ids_although_no_response_came(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addException(new NetworkException('timed out', $this->factory->createRequest('GET', 'https://x')));

        $e = $this->catchSdk(fn() => $sdk->withTransactionId('t-1', static fn() => $sdk->getPaymentStatus('pay-1')));

        $this->assertSame(ErrorCode::NetworkError, $e->errorCode);
        $this->assertSame($this->requests()[1]->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
        $this->assertSame('t-1', $e->transactionId);
    }

    #[Test]
    public function a_failed_token_refresh_carries_the_token_requests_ids(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(401, [], '{}'));
        $this->mockClient->addResponse(new Response(503, [], ''));

        $e = $this->catchSdk(fn() => $sdk->getPaymentStatus('pay-1'));

        [, $rejected, $refresh] = $this->requests();
        $this->assertSame(ErrorCode::AuthRefreshFailed, $e->errorCode);
        $this->assertSame($refresh->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
        $this->assertNotSame($rejected->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
        $this->assertSame($rejected->getHeaderLine('X-GoPay-Transaction-Id'), $e->transactionId);
    }

    #[Test]
    public function a_failed_authenticate_carries_its_ids(): void
    {
        $sdk = $this->sdk();
        $this->mockClient->addResponse(new Response(401, [], '{}'));

        $e = $this->catchSdk(fn() => $sdk->authenticate('id', 'wrong', 'payment:read'));

        $this->assertSame($this->requests()[0]->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
    }

    #[Test]
    public function an_error_raised_before_any_request_carries_no_ids(): void
    {
        $sdk = $this->sdk();

        $e = $this->catchSdk(fn() => $sdk->getPaymentStatus('pay-1'));

        $this->assertSame(ErrorCode::AuthTokenMissing, $e->errorCode);
        $this->assertNull($e->traceId);
        $this->assertNull($e->transactionId);
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    #[Test]
    public function an_unreadable_response_carries_the_ids_of_its_request(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(new Response(200, [], 'not json'));

        $e = $this->catchSdk(fn() => $sdk->getPaymentStatus('pay-1'));

        $this->assertSame(ErrorCode::UnexpectedResponse, $e->errorCode);
        $this->assertSame($this->requests()[1]->getHeaderLine('X-GoPay-Trace-Id'), $e->traceId);
    }

    #[Test]
    public function an_error_after_logout_does_not_borrow_the_ids_of_the_previous_request(): void
    {
        $sdk = $this->authenticated();
        $this->mockClient->addResponse(self::payment());
        $sdk->getPaymentStatus('pay-1');
        $sdk->logout();

        $e = $this->catchSdk(fn() => $sdk->getPaymentStatus('pay-1'));

        $this->assertSame(ErrorCode::AuthTokenMissing, $e->errorCode);
        $this->assertNull($e->traceId);
        $this->assertNull($e->transactionId);
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function a_refused_webhook_does_not_borrow_the_ids_of_an_earlier_request(): void
    {
        $sdk = $this->authenticated();

        $e = $this->catchSdk(fn() => $sdk->verifyWebhook('{}', null));

        $this->assertSame(ErrorCode::WebhookInvalid, $e->errorCode);
        $this->assertNull($e->traceId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @var (\Closure(\Throwable): void)|null */
    private ?\Closure $onSeen = null;

    private function sdk(?Config $config = null): GoPayClient
    {
        $config ??= new Config(
            environment: Environment::Sandbox,
            baseUrl: 'https://mock.example.com',
            onError: function (\Throwable $e): void {
                $this->seen[] = $e;
                if ($this->onSeen !== null) {
                    ($this->onSeen)($e);
                }
            },
        );

        return new GoPayClient($config, $this->mockClient, $this->factory, $this->factory);
    }

    private function authenticated(): GoPayClient
    {
        $sdk = $this->sdk();
        $this->mockClient->addResponse(new Response(200, [], self::TOKEN));
        $sdk->authenticate('id', 'secret', 'payment:read event:read');

        return $sdk;
    }

    private static function payment(): Response
    {
        return new Response(200, [], '{"id":"pay-1","state":"CREATED","amount":100,"currency":"CZK"}');
    }

    /** @return list<RequestInterface> */
    private function requests(): array
    {
        return array_values($this->mockClient->getRequests());
    }

    private function catchSdk(callable $call): GoPaySdkException
    {
        try {
            $call();
        } catch (GoPaySdkException $e) {
            return $e;
        }
        $this->fail('expected a GoPaySdkException');
    }

    private function catchHttp(callable $call): GoPayHttpException
    {
        try {
            $call();
        } catch (GoPayHttpException $e) {
            return $e;
        }
        $this->fail('expected a GoPayHttpException');
    }
}

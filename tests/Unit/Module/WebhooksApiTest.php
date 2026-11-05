<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Module;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Event\UnknownEvent;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\JWKSet;
use GoPay\Payments\Generated\Model\PaymentCanceledEvent;
use GoPay\Payments\Generated\Model\PaymentPaidEvent;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Module\WebhooksApi;
use GoPay\Payments\Webhook\Base64Url;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class WebhooksApiTest extends ModuleTestCase
{
    private const EVENT = '{"id":"01M5YWPW85D1XJMZ76N7AJHY49","type":"payments.paid","occurred_at":"2026-10-27T14:37:27.167Z","data":{"payment_id":"7639814693","charge_id":"ch-1"}}';

    private float $now = 1000.0;

    private WebhooksApi $api;

    private static ?\OpenSSLAsymmetricKey $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new WebhooksApi($this->http, fn(): float => $this->now);
    }

    // -------------------------------------------------------------------------
    // The real webhook
    // -------------------------------------------------------------------------

    #[Test]
    public function verifies_a_webhook_the_gateway_signed(): void
    {
        $fixture = self::fixture();
        $this->queueJson($fixture->jwks);

        $event = $this->api->verifyWebhook(self::fixtureBody(), self::fixtureSignature());

        $this->assertInstanceOf(PaymentCanceledEvent::class, $event);
        $this->assertSame('01M5YWPW85D1XJMZ76N7AJHY49', $event->getId());
        $this->assertSame('payments.canceled', $event->getType());
        $this->assertSame('7639814693', $event->getData()->getPaymentId());
        $this->assertSame('2026-10-27T14:37:27.167+00:00', $event->getOccurredAt()->format('Y-m-d\TH:i:s.vP'));

        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/webhooks/public-keys', $request->getUri()->getPath());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function takes_the_header_as_psr7_returns_it(): void
    {
        $fixture = self::fixture();
        $this->queueJson($fixture->jwks);

        $event = $this->api->verifyWebhook(self::fixtureBody(), [self::fixtureSignature()]);

        $this->assertInstanceOf(PaymentCanceledEvent::class, $event);
    }

    #[Test]
    public function refuses_the_real_webhook_with_one_byte_changed(): void
    {
        $fixture = self::fixture();
        $this->queueJson($fixture->jwks);
        $body = str_replace('7639814693', '7639814694', self::fixtureBody());

        $this->assertInvalid(fn() => $this->api->verifyWebhook($body, self::fixtureSignature()), 'the signature does not match the body');
    }

    #[Test]
    public function refuses_the_real_signature_on_a_reencoded_body(): void
    {
        $fixture = self::fixture();
        $this->queueJson($fixture->jwks);
        $body = json_encode(json_decode(self::fixtureBody()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $this->assertInvalid(fn() => $this->api->verifyWebhook($body, self::fixtureSignature()), 'the signature does not match the body');
    }

    // -------------------------------------------------------------------------
    // Signature header
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string|list<string>|null, string}>
     */
    public static function malformedHeaders(): iterable
    {
        $header = Base64Url::encode('{"alg":"RS256","kid":"k1"}');
        $signature = Base64Url::encode('sig');

        yield 'missing' => [null, 'exactly one X-GoPay-Signature header is required'];
        yield 'empty' => ['', 'exactly one X-GoPay-Signature header is required'];
        yield 'two headers' => [["{$header}..{$signature}", "{$header}..{$signature}"], 'exactly one X-GoPay-Signature header is required'];
        yield 'no headers' => [[], 'exactly one X-GoPay-Signature header is required'];
        yield 'attached payload' => ["{$header}.e30.{$signature}", 'X-GoPay-Signature is not a detached JWS'];
        yield 'two parts' => ["{$header}.{$signature}", 'X-GoPay-Signature is not a detached JWS'];
        yield 'four parts' => ["{$header}..{$signature}.x", 'X-GoPay-Signature is not a detached JWS'];
        yield 'padded base64' => ["{$header}..{$signature}=", 'X-GoPay-Signature is not base64url'];
        yield 'standard base64' => ["{$header}..ab+/", 'X-GoPay-Signature is not base64url'];
        yield 'empty signature' => ["{$header}..", 'X-GoPay-Signature is not base64url'];
        yield 'header not json' => [Base64Url::encode('nope') . "..{$signature}", 'the JWS header is not a JSON object'];
        yield 'header a list' => [Base64Url::encode('["RS256"]') . "..{$signature}", 'the JWS header is not a JSON object'];
        yield 'HS256' => [Base64Url::encode('{"alg":"HS256","kid":"k1"}') . "..{$signature}", 'the JWS header is not RS256'];
        yield 'alg none' => [Base64Url::encode('{"alg":"none","kid":"k1"}') . "..{$signature}", 'the JWS header is not RS256'];
        yield 'no kid' => [Base64Url::encode('{"alg":"RS256"}') . "..{$signature}", 'the JWS header names no key'];
        yield 'empty kid' => [Base64Url::encode('{"alg":"RS256","kid":""}') . "..{$signature}", 'the JWS header names no key'];
        yield 'numeric kid' => [Base64Url::encode('{"alg":"RS256","kid":1}') . "..{$signature}", 'the JWS header names no key'];
        yield 'crit' => [Base64Url::encode('{"alg":"RS256","kid":"k1","crit":["b64"]}') . "..{$signature}", 'the JWS header uses an unsupported extension'];
        yield 'b64 false' => [Base64Url::encode('{"alg":"RS256","kid":"k1","b64":false}') . "..{$signature}", 'the JWS header uses an unsupported extension'];
    }

    /**
     * @param string|list<string>|null $header
     */
    #[Test]
    #[DataProvider('malformedHeaders')]
    public function refuses_a_malformed_signature_header_without_calling_gopay(string|array|null $header, string $reason): void
    {
        $this->assertInvalid(fn() => $this->api->verifyWebhook(self::EVENT, $header), $reason);
        $this->assertCount(0, $this->mockClient->getRequests());
    }

    // -------------------------------------------------------------------------
    // Body
    // -------------------------------------------------------------------------

    #[Test]
    public function returns_an_unknown_type_as_an_unknown_event_rather_than_an_error(): void
    {
        $this->queueJson(self::jwks('k1'));
        $body = '{"id":"evt-9","type":"payouts.sent","occurred_at":"2026-11-01T10:00:00Z","data":{"payout_id":"p-1","nested":{"a":[1,2]}}}';

        $event = $this->api->verifyWebhook($body, self::sign($body, 'k1'));

        $this->assertInstanceOf(UnknownEvent::class, $event);
        $this->assertSame('evt-9', $event->getId());
        $this->assertSame('payouts.sent', $event->getType());
        $this->assertSame(['payout_id' => 'p-1', 'nested' => ['a' => [1, 2]]], $event->getData());
        $this->assertSame('2026-11-01T10:00:00+00:00', $event->getOccurredAt()->format(\DATE_ATOM));
    }

    #[Test]
    public function dispatches_each_type_onto_its_own_model(): void
    {
        $this->queueJson(self::jwks('k1'));

        $event = $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));

        $this->assertInstanceOf(PaymentPaidEvent::class, $event);
        $this->assertSame('ch-1', $event->getData()->getChargeId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEvents(): iterable
    {
        yield 'not json' => ['{"id":'];
        yield 'a list' => ['[]'];
        yield 'no id' => ['{"type":"payments.paid","occurred_at":"2026-10-27T14:37:27Z","data":{}}'];
        yield 'numeric id' => ['{"id":1,"type":"payments.paid","occurred_at":"2026-10-27T14:37:27Z","data":{}}'];
        yield 'no type' => ['{"id":"e","occurred_at":"2026-10-27T14:37:27Z","data":{}}'];
        yield 'no occurred_at' => ['{"id":"e","type":"payments.paid","data":{}}'];
        yield 'data a list' => ['{"id":"e","type":"payments.paid","occurred_at":"2026-10-27T14:37:27Z","data":[]}'];
        yield 'bad instant' => ['{"id":"e","type":"payments.paid","occurred_at":"yesterday-ish","data":{}}'];
        yield 'bad instant, unknown type' => ['{"id":"e","type":"x.y","occurred_at":"not a time","data":{}}'];
    }

    #[Test]
    #[DataProvider('notEvents')]
    public function refuses_a_signed_body_that_is_not_an_event(string $body): void
    {
        $this->queueJson(self::jwks('k1'));

        $this->assertInvalid(fn() => $this->api->verifyWebhook($body, self::sign($body, 'k1')), 'the body is not an event');
    }

    // -------------------------------------------------------------------------
    // Keys
    // -------------------------------------------------------------------------

    #[Test]
    public function fetches_the_keys_once_for_many_webhooks(): void
    {
        $this->queueJson(self::jwks('k1'));

        for ($i = 0; $i < 3; $i++) {
            $this->now += 100;
            $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        }

        $this->assertCount(1, $this->mockClient->getRequests());
    }

    #[Test]
    public function an_unknown_key_within_ten_seconds_is_unavailable_not_invalid(): void
    {
        $this->queueJson(self::jwks('k1'));
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));

        $this->now += 9;

        $e = $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k2')));
        $this->assertSame(ErrorCode::WebhookKeysUnavailable, $e->errorCode);
        $this->assertCount(1, $this->mockClient->getRequests(), 'a made-up kid must not become a call to GoPay');
    }

    #[Test]
    public function a_key_rotated_in_is_picked_up_by_a_refetch(): void
    {
        $this->queueJson(self::jwks('k1'));
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->queueJson(self::jwks('k1', 'k2'));

        $this->now += 10;

        $this->assertInstanceOf(PaymentPaidEvent::class, $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k2')));
        $this->assertCount(2, $this->mockClient->getRequests());
    }

    #[Test]
    public function a_key_gopay_does_not_publish_after_a_refetch_is_invalid(): void
    {
        $this->queueJson(self::jwks('k1'));

        $this->assertInvalid(
            fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k9')),
            'it is signed with a key GoPay does not publish',
        );
    }

    #[Test]
    public function a_key_withdrawn_after_an_hour_stops_verifying(): void
    {
        $this->queueJson(self::jwks('k1'));
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->queueJson(self::jwks('k2'));

        $this->now += 3600;

        $this->assertInvalid(
            fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')),
            'it is signed with a key GoPay does not publish',
        );
    }

    #[Test]
    public function a_failed_hourly_refetch_keeps_the_last_keys(): void
    {
        $this->queueJson(self::jwks('k1'));
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->mockClient->addResponse(new Response(500, [], '{"errors":[]}'));

        $this->now += 3600;

        $this->assertInstanceOf(PaymentPaidEvent::class, $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));
        $this->assertCount(2, $this->mockClient->getRequests());
    }

    #[Test]
    public function keys_that_do_not_load_are_unavailable_and_name_the_scope_on_403(): void
    {
        $this->mockClient->addResponse(new Response(403, [], '{"errors":[{"error_code":"FORBIDDEN"}]}'));

        $e = $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));

        $this->assertSame(ErrorCode::WebhookKeysUnavailable, $e->errorCode);
        $this->assertStringContainsString('shared:read', $e->getMessage());
        $previous = $e->getPrevious();
        $this->assertInstanceOf(GoPayHttpException::class, $previous);
        $this->assertSame(403, $previous->status);
    }

    #[Test]
    public function a_failed_fetch_names_no_scope_when_it_is_not_a_403(): void
    {
        $this->mockClient->addResponse(new Response(500, [], 'oops'));

        $e = $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));

        $this->assertSame(ErrorCode::WebhookKeysUnavailable, $e->errorCode);
        $this->assertStringNotContainsString('shared:read', $e->getMessage());
    }

    #[Test]
    public function a_failed_fetch_is_retried_after_the_throttle(): void
    {
        $this->mockClient->addResponse(new Response(503, [], ''));
        $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));
        $this->queueJson(self::jwks('k1'));

        $this->now += 10;

        $this->assertInstanceOf(PaymentPaidEvent::class, $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableJwks(): iterable
    {
        yield 'EC' => [['kty' => 'EC', 'use' => 'sig', 'alg' => 'RS256', 'n' => 'AQAB', 'e' => 'AQAB']];
        yield 'encryption key' => [['kty' => 'RSA', 'use' => 'enc', 'alg' => 'RS256', 'n' => 'AQAB', 'e' => 'AQAB']];
        yield 'RS512' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS512', 'n' => 'AQAB', 'e' => 'AQAB']];
        yield 'n not base64url' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => 'a+b/', 'e' => 'AQAB']];
        yield 'no e' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => 'AQAB']];
    }

    /**
     * @param array<string, mixed> $broken
     */
    #[Test]
    #[DataProvider('unusableJwks')]
    public function skips_a_key_it_cannot_use_and_keeps_the_others(array $broken): void
    {
        $set = self::jwks('k1');
        $set['keys'][] = ['kid' => 'bad'] + $broken;
        $set['keys'][] = 'not a key';
        $this->queueJson($set);
        $this->queueJson($set);

        $this->assertInstanceOf(PaymentPaidEvent::class, $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));
        $this->assertInvalid(
            fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'bad')),
            'it is signed with a key GoPay does not publish',
            freshClock: true,
        );
    }

    #[Test]
    public function a_key_set_without_keys_verifies_nothing(): void
    {
        $this->queueJson(['something' => 'else']);

        $this->assertInvalid(
            fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')),
            'it is signed with a key GoPay does not publish',
        );
    }

    #[Test]
    public function a_failed_refresh_is_retried_after_ten_seconds_not_after_another_hour(): void
    {
        $this->queueJson(self::jwks('k1'));
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->mockClient->addResponse(new Response(500, [], '{"errors":[]}'));

        $this->now += 3600;
        // The refresh fails: the last set keeps verifying meanwhile.
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->now += 9;
        $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->assertCount(2, $this->mockClient->getRequests(), 'not retried within ten seconds');

        // GoPay withdrew k1: the retry ten seconds later learns it, not an hour later.
        $this->queueJson(self::jwks('k2'));
        $this->now += 1;
        $this->assertInvalid(
            fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')),
            'it is signed with a key GoPay does not publish',
        );
        $this->assertCount(3, $this->mockClient->getRequests());
    }

    #[Test]
    public function within_ten_seconds_of_a_failed_fetch_the_cause_and_scope_hint_stay(): void
    {
        $this->mockClient->addResponse(new Response(403, [], '{"errors":[{"error_code":"FORBIDDEN"}]}'));
        $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));

        $this->now += 5;
        $e = $this->catch(fn() => $this->api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));

        $this->assertSame(ErrorCode::WebhookKeysUnavailable, $e->errorCode);
        $this->assertStringContainsString('shared:read', $e->getMessage());
        $previous = $e->getPrevious();
        $this->assertInstanceOf(GoPayHttpException::class, $previous);
        $this->assertSame(403, $previous->status);
        $this->assertCount(1, $this->mockClient->getRequests());
    }

    #[Test]
    public function on_error_hears_neither_a_forged_webhook_nor_unavailable_keys_only_the_failed_fetch_once(): void
    {
        $seen = [];
        $api = new WebhooksApi($this->clientReportingTo($seen), fn(): float => $this->now);

        // Forged: anyone can send one.
        $this->catch(fn() => $api->verifyWebhook(self::EVENT, null));
        $this->assertSame([], $seen);

        // The keys do not load: the fetch is reported, once; WebhookKeysUnavailable is not.
        $this->mockClient->addResponse(new Response(403, [], '{}'));
        $this->catch(fn() => $api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1')));
        $this->assertCount(1, $seen);
        $this->assertInstanceOf(GoPayHttpException::class, $seen[0]);

        // A made-up kid within the interval: unavailable, and not reported again.
        $this->now += 1;
        $this->catch(fn() => $api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'made-up')));
        $this->assertCount(1, $seen);
    }

    #[Test]
    public function a_failed_hourly_refresh_is_reported_once_even_when_the_cached_key_verifies(): void
    {
        $seen = [];
        $api = new WebhooksApi($this->clientReportingTo($seen), fn(): float => $this->now);
        $this->queueJson(self::jwks('k1'));
        $api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $this->mockClient->addResponse(new Response(503, [], ''));

        $this->now += 3600;
        $api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));
        $api->verifyWebhook(self::EVENT, self::sign(self::EVENT, 'k1'));

        $this->assertCount(1, $seen, 'the refresh failed once, so it is heard once');
        $this->assertInstanceOf(GoPayHttpException::class, $seen[0]);
    }

    #[Test]
    public function get_webhook_public_keys_returns_the_set(): void
    {
        $this->queueJson(self::fixture()->jwks);

        $set = $this->api->getWebhookPublicKeys();

        $this->assertInstanceOf(JWKSet::class, $set);
        $this->assertSame(['sign-2026-01', 'sign-2026-02'], array_map(static fn($k) => $k->getKid(), $set->getKeys()));
        $this->assertSame('/webhooks/public-keys', $this->lastRequest()->getUri()->getPath());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<\Throwable> $seen
     */
    private function clientReportingTo(array &$seen): HttpClient
    {
        $http = new HttpClient(
            config: new Config(
                environment: Environment::Sandbox,
                baseUrl: 'https://mock.example.com',
                onError: static function (\Throwable $e) use (&$seen): void {
                    $seen[] = $e;
                },
            ),
            client: $this->mockClient,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $http->getTokenStore()->setToken('test-token', 3600);

        return $http;
    }

    private function assertInvalid(callable $call, string $reason, bool $freshClock = false): void
    {
        if ($freshClock) {
            $this->now += 10;
        }
        $e = $this->catch($call);
        $this->assertSame(ErrorCode::WebhookInvalid, $e->errorCode);
        $this->assertSame("[GoPaySDK] Not a valid GoPay webhook: {$reason}.", $e->getMessage());
    }

    private function catch(callable $call): GoPaySdkException
    {
        try {
            $call();
        } catch (GoPaySdkException $e) {
            return $e;
        }
        $this->fail('expected a GoPaySdkException');
    }

    private static function fixture(): \stdClass
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/webhook-payments-canceled.json'));
        self::assertInstanceOf(\stdClass::class, $fixture);

        return $fixture;
    }

    private static function fixtureSignature(): string
    {
        $signature = self::fixture()->x_gopay_signature;
        self::assertIsString($signature);

        return $signature;
    }

    private static function fixtureBody(): string
    {
        $fixture = self::fixture();
        self::assertIsString($fixture->body_base64);

        return (string) base64_decode($fixture->body_base64, true);
    }

    private static function privateKey(): \OpenSSLAsymmetricKey
    {
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
            self::$privateKey = $key;
        }

        return self::$privateKey;
    }

    /**
     * Every kid is the same test key: what is under test is which kids the SDK trusts.
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    private static function jwks(string ...$kids): array
    {
        $details = openssl_pkey_get_details(self::privateKey());
        self::assertIsArray($details);
        /** @var array{n: string, e: string} $rsa */
        $rsa = $details['rsa'];
        $keys = [];
        foreach ($kids as $kid) {
            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $kid,
                'alg' => 'RS256',
                'n' => Base64Url::encode($rsa['n']),
                'e' => Base64Url::encode($rsa['e']),
            ];
        }

        return ['keys' => $keys];
    }

    private static function sign(string $body, string $kid): string
    {
        $header = Base64Url::encode((string) json_encode(['alg' => 'RS256', 'kid' => $kid]));
        openssl_sign($header . '.' . Base64Url::encode($body), $signature, self::privateKey(), OPENSSL_ALGO_SHA256);
        self::assertIsString($signature);

        return $header . '..' . Base64Url::encode($signature);
    }
}

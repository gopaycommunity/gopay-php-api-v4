<?php

declare(strict_types=1);

namespace GoPay\Payments;

use GoPay\Payments\Event\EventFactory;
use GoPay\Payments\Event\EventPage;
use GoPay\Payments\Event\UnknownEvent;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\JWKSet;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Generated\Model\RecurrenceDetails;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\Http\Correlation;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Module\AuthApi;
use GoPay\Payments\Module\CardsApi;
use GoPay\Payments\Module\EventsApi;
use GoPay\Payments\Module\LinksApi;
use GoPay\Payments\Module\PaymentsApi;
use GoPay\Payments\Module\RecurrencesApi;
use GoPay\Payments\Module\RefundsApi;
use GoPay\Payments\Module\WebhooksApi;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * GoPay Payments API v4 — PHP server-side SDK.
 *
 * Quick start:
 * ```php
 * use GoPay\Payments\GoPayClient;
 * use GoPay\Payments\Config;
 * use GoPay\Payments\Environment;
 *
 * $sdk = new GoPayClient(new Config(environment: Environment::Sandbox));
 * $sdk->authenticate('YOUR_CLIENT_ID', 'YOUR_CLIENT_SECRET', 'payment:write payment:read');
 *
 * $payment = $sdk->createPayment('YOUR_GOID', [
 *     'amount'       => 1000,
 *     'currency'     => 'CZK',
 *     'order_number' => 'ORDER-001',
 *     'customer'     => ['email' => 'customer@example.com'],
 *     'callback'     => [
 *         'notification_url' => 'https://yourshop.com/notify',
 *         'return_url'       => 'https://yourshop.com/return',
 *     ],
 * ]);
 * ```
 *
 * @phpstan-import-type GoPayEvent from EventFactory
 * @phpstan-import-type ListEventsParams from EventsApi
 *
 * @SuppressWarnings("php:S1448")
 */
final class GoPayClient
{
    private readonly HttpClient $http;
    private readonly AuthApi $auth;
    private readonly PaymentsApi $payments;
    private readonly CardsApi $cards;
    private readonly RefundsApi $refunds;
    private readonly LinksApi $links;
    private readonly RecurrencesApi $recurrences;
    private readonly EventsApi $events;
    private readonly WebhooksApi $webhooks;

    public function __construct(
        Config $config = new Config(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->http = new HttpClient($config, $httpClient, $requestFactory, $streamFactory);
        $this->auth = new AuthApi($this->http);
        $this->payments = new PaymentsApi($this->http);
        $this->cards = new CardsApi($this->http);
        $this->refunds = new RefundsApi($this->http);
        $this->links = new LinksApi($this->http);
        $this->recurrences = new RecurrencesApi($this->http);
        $this->events = new EventsApi($this->http);
        $this->webhooks = new WebhooksApi($this->http);
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /**
     * Authenticate using the OAuth2 client_credentials grant.
     *
     * Stores the token internally. All subsequent API calls attach the Bearer
     * token automatically. Tokens are refreshed transparently before expiry.
     * The raw token is not returned — it must remain server-side only.
     *
     * POST /oauth2/token
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function authenticate(string $clientId, string $clientSecret, string $scope): void
    {
        $this->inTransaction(function () use ($clientId, $clientSecret, $scope): void {
            $this->auth->authenticate($clientId, $clientSecret, $scope);
        });
    }

    /**
     * Returns true if an access token is currently stored.
     * Does not check expiry — expired tokens are refreshed transparently.
     */
    public function isAuthenticated(): bool
    {
        return $this->auth->isAuthenticated();
    }

    /**
     * Clear all stored tokens and credentials.
     * Subsequent API calls will throw until re-authenticated.
     */
    public function logout(): void
    {
        $this->auth->logout();
    }

    /**
     * Store the shareable key at runtime.
     * Used to pass to getBrowserKeys() for browser SDK initialisation.
     */
    public function setShareableKey(string $key): void
    {
        $this->auth->setShareableKey($key);
    }

    /**
     * Return the shareable_key + client_id pair for browser SDK initialisation.
     * Pass the result to the browser page; it is safe to expose in HTML/JS.
     * Never exposes the client_secret.
     *
     * @throws GoPaySdkException
     *
     * @return array{shareable_key: string, client_id: string}
     */
    public function getBrowserKeys(): array
    {
        return $this->auth->getBrowserKeys();
    }

    // =========================================================================
    // Payments
    // =========================================================================

    /**
     * Create a new payment session.
     *
     * POST /eshops/{goid}/payments
     *
     * The returned `gw_url` is GoPay's hosted payment gateway, offering the
     * customer every method the eshop has enabled — a supported alternative to
     * createPayment() → chargePayment(), not a legacy field. It cannot be
     * embedded in the merchant's own checkout, which is the reason to drive the
     * payment here instead, not any gap in what v4 covers. Drive one or the
     * other for a given payment attempt, never both; getPaymentStatus() reports
     * the outcome either way.
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createPayment(string $goid, array $params): PaymentDetails
    {
        return $this->inTransaction(fn() => $this->payments->createPayment($goid, $params));
    }

    /**
     * Retrieve the current status of a payment.
     *
     * GET /payments/{payment_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getPaymentStatus(string $paymentId): PaymentDetails
    {
        return $this->inTransaction(fn() => $this->payments->getPaymentStatus($paymentId));
    }

    /**
     * Cancel a payment that has not been charged yet.
     * Requires `payment:write` scope.
     *
     * DELETE /payments/{payment_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function cancelPayment(string $paymentId): void
    {
        $this->inTransaction(function () use ($paymentId): void {
            $this->payments->cancelPayment($paymentId);
        });
    }

    /**
     * Charge a payment using a payment instrument (card token, Apple Pay, Google Pay).
     *
     * POST /payments/{payment_id}/charge
     *
     * The response is not the outcome — the charge is usually still PROCESSING.
     * Follow it with awaitChargeState(), which returns once the charge succeeds
     * or needs 3DS. On ACTION_REQUIRED redirect the customer to the action's URL
     * immediately — the bank's authentication session times out quickly, and
     * a customer who arrives late finds the payment cancelled. Do order bookkeeping
     * on the return URL or the webhook, never before the redirect.
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function chargePayment(string $paymentId, array $params): PaymentChargeResponse
    {
        return $this->inTransaction(fn() => $this->payments->chargePayment($paymentId, $params));
    }

    /**
     * Retrieve the current state of a payment charge.
     *
     * GET /payments/{payment_id}/charge
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getChargeState(string $paymentId): PaymentChargeStatusResponse
    {
        return $this->inTransaction(fn() => $this->payments->getChargeState($paymentId));
    }

    /**
     * Retrieve Google Pay configuration (pre-filled paymentDataRequest).
     *
     * GET /payments/{payment_id}/google-pay/info
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return array<string, mixed>
     */
    public function getGooglePayInfo(string $paymentId): array
    {
        return $this->inTransaction(fn() => $this->payments->getGooglePayInfo($paymentId));
    }

    /**
     * Retrieve Apple Pay configuration (applepayVersion, applePayPaymentRequest).
     *
     * GET /payments/{payment_id}/apple-pay/info
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return array<string, mixed>
     */
    public function getApplePayInfo(string $paymentId): array
    {
        return $this->inTransaction(fn() => $this->payments->getApplePayInfo($paymentId));
    }

    /**
     * Server-side Apple Pay merchant validation.
     * Forward the browser's validationURL and pass the response back to
     * ApplePaySession.completeMerchantValidation().
     *
     * POST /payments/{payment_id}/apple-pay/validate
     *
     * @param array<string, mixed>|null $body
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return array<string, mixed>
     */
    public function validateApplePayMerchant(
        string $paymentId,
        ?array $body = null,
        ?string $origin = null,
    ): array {
        return $this->inTransaction(fn() => $this->payments->validateApplePayMerchant($paymentId, $body, $origin));
    }

    /**
     * Retrieve QR payment information (recipient details + base64 QR image).
     *
     * GET /payments/{payment_id}/qr-payment/info
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getQrPaymentInfo(string $paymentId, ?string $format = null): QRPaymentDetails
    {
        return $this->inTransaction(fn() => $this->payments->getQrPaymentInfo($paymentId, $format));
    }

    /**
     * Poll the charge state synchronously until the charge is decided.
     * Returns on SUCCEEDED, or on ACTION_REQUIRED while a 3DS step with a
     * redirect URL waits for the customer; throws CHARGE_FAILED on FAILED
     * state, CHARGE_TIMEOUT if the charge isn't decided in time.
     *
     * Call it right after chargePayment() — the 3DS redirect URL usually only
     * appears here, not in the POST response. On ACTION_REQUIRED redirect the
     * customer to getAction()->getRedirectUrl() immediately; only SUCCEEDED
     * means paid. A webhook is no substitute: no notification arrives for a
     * 3DS challenge.
     *
     * WARNING: This method calls usleep() in a loop and blocks the PHP process
     * until the charge is decided — usually a few seconds, at most
     * $timeoutSeconds. In PHP-FPM or mod_php deployments that worker is
     * unavailable to serve other requests while it polls.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function awaitChargeState(
        string $paymentId,
        int $timeoutSeconds = 30,
        int $pollIntervalMs = 1_000,
    ): PaymentChargeStatusResponse {
        return $this->inTransaction(fn() => $this->payments->awaitChargeState($paymentId, $timeoutSeconds, $pollIntervalMs));
    }

    // =========================================================================
    // Cards
    // =========================================================================

    /**
     * Retrieve details of a stored permanent card token.
     * Requires `card:read` scope.
     *
     * GET /cards/tokens/{card_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getCardDetails(string $cardId): PermanentCardTokenDetails
    {
        return $this->inTransaction(fn() => $this->cards->getCardDetails($cardId));
    }

    /**
     * Delete a stored permanent card token.
     * Requires `card:write` scope.
     *
     * DELETE /cards/tokens/{card_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function deleteCard(string $cardId): void
    {
        $this->inTransaction(function () use ($cardId): void {
            $this->cards->deleteCard($cardId);
        });
    }

    /**
     * Tokenize the JWE payload received from the browser iframe.
     * Requires `card:write` scope.
     *
     * POST /cards/tokens
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function tokenizeEncryptedCard(string $payload): PermanentCardTokenDetails
    {
        return $this->inTransaction(fn() => $this->cards->tokenizeEncryptedCard($payload));
    }

    // =========================================================================
    // Refunds
    // =========================================================================

    /**
     * Refund a payment fully or partially.
     * Requires `payment:write` scope.
     *
     * POST /payments/{payment_id}/refunds
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function refundPayment(string $paymentId, array $params): RefundDetails
    {
        return $this->inTransaction(fn() => $this->refunds->refundPayment($paymentId, $params));
    }

    /**
     * List all refunds for a payment.
     * Requires `payment:read` scope.
     *
     * GET /payments/{payment_id}/refunds
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return list<RefundDetails>
     */
    public function listRefunds(string $paymentId): array
    {
        return $this->inTransaction(fn() => $this->refunds->listRefunds($paymentId));
    }

    /**
     * Retrieve details of a single refund.
     * Requires `payment:read` scope.
     *
     * GET /refunds/{refund_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getRefund(string $refundId): RefundDetails
    {
        return $this->inTransaction(fn() => $this->refunds->getRefund($refundId));
    }

    /**
     * Poll a refund until it reaches `SUCCESS` or `FAILED`.
     * Requires `payment:read` scope.
     *
     * GET /refunds/{refund_id}
     *
     * A `FAILED` refund is returned, not raised — the refundable amount is untouched,
     * so the caller decides whether to retry.
     *
     * WARNING: This method calls usleep() in a loop and blocks the PHP process
     * for up to $timeoutSeconds. In PHP-FPM or mod_php deployments this holds a
     * worker unavailable to serve other requests for the full polling window.
     * Prefer a webhook-driven approach for production web applications: let the
     * GoPay notification call your server, then use getRefund() once.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function awaitRefundState(
        string $refundId,
        int $timeoutSeconds = 30,
        int $pollIntervalMs = 1_000,
    ): RefundDetails {
        return $this->inTransaction(fn() => $this->refunds->awaitRefundState($refundId, $timeoutSeconds, $pollIntervalMs));
    }

    // =========================================================================
    // Payment links
    // =========================================================================

    /**
     * Create a payment link.
     * Requires `payment:write` scope.
     *
     * POST /eshops/{goid}/links
     *
     * The link stores the payment data; the payment itself is created when a customer
     * opens the returned `url`. A reusable link creates a new payment on every visit,
     * all of them carrying the same `order_number` and `notification_url`.
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createPaymentLink(string $goid, array $params): LinkDetails
    {
        return $this->inTransaction(fn() => $this->links->createPaymentLink($goid, $params));
    }

    /**
     * Retrieve the current settings and state of a payment link.
     * Requires `payment:read` scope.
     *
     * GET /eshops/{goid}/links/{link_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function linkStatus(string $goid, string $linkId): LinkDetails
    {
        return $this->inTransaction(fn() => $this->links->linkStatus($goid, $linkId));
    }

    /**
     * Disable a link so it can no longer start a new payment.
     * Requires `payment:write` scope.
     *
     * DELETE /eshops/{goid}/links/{link_id}
     *
     * Not a delete — the link stays readable and reports stop reason `FROM_API`.
     * Disabling an already-inactive link answers 409.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function disableLink(string $goid, string $linkId): void
    {
        $this->inTransaction(function () use ($goid, $linkId): void {
            $this->links->disableLink($goid, $linkId);
        });
    }

    // =========================================================================
    // Recurrences
    // =========================================================================

    /**
     * Create a recurrence. Returns it in state `NEW`.
     * Requires `payment:write` scope.
     *
     * POST /eshops/{goid}/recurrences
     *
     * The body is a discriminated union on `type`. An `AUTO` recurrence carries a
     * `schedule` and GoPay creates the payments itself; an `ON_DEMAND` one must not
     * carry one, because the merchant asks for each further payment with
     * `recurrenceNext()`. `recurrence_date_to` is required in both, as a plain
     * `yyyy-MM-dd` date. Unknown fields are rejected rather than ignored.
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createRecurrence(string $goid, array $params): RecurrenceDetails
    {
        return $this->inTransaction(fn() => $this->recurrences->createRecurrence($goid, $params));
    }

    /**
     * Retrieve the current state of a recurrence and the payment it last created.
     * Requires `payment:read` scope.
     *
     * GET /recurrences/{rec_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function recurrenceStatus(string $recId): RecurrenceDetails
    {
        return $this->inTransaction(fn() => $this->recurrences->recurrenceStatus($recId));
    }

    /**
     * Stop a recurrence permanently.
     * Requires `payment:write` scope.
     *
     * DELETE /recurrences/{rec_id}
     *
     * Moves it to `STOPPED` with stop reason `CANCELLED_VIA_API`. Cannot be undone;
     * stopping or starting an already stopped recurrence answers 409.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function stopRecurrence(string $recId): void
    {
        $this->inTransaction(function () use ($recId): void {
            $this->recurrences->stopRecurrence($recId);
        });
    }

    /**
     * Start a recurrence by creating its first payment.
     * Requires `payment:write` scope.
     *
     * POST /recurrences/{rec_id}/start
     *
     * The recurrence moves to `REQUESTED`, not `STARTED` — it becomes `STARTED` only
     * once the customer pays the returned payment at its `gw_url`. The optional body
     * overrides `amount`, `order_number`, `order_description`, `additional_params`,
     * `customer` or `callback` for this one payment.
     *
     * @param array<string, mixed>|null $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function startRecurrence(string $recId, ?array $params = null): PaymentDetails
    {
        return $this->inTransaction(fn() => $this->recurrences->startRecurrence($recId, $params));
    }

    /**
     * Create a further payment from a recurrence already in state `STARTED`.
     * Requires `payment:write` scope.
     *
     * POST /recurrences/{rec_id}/next
     *
     * Answers 409 until the recurrence has been started and its first payment paid.
     *
     * @param array<string, mixed>|null $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function recurrenceNext(string $recId, ?array $params = null): PaymentDetails
    {
        return $this->inTransaction(fn() => $this->recurrences->recurrenceNext($recId, $params));
    }

    // =========================================================================
    // Request correlation
    // =========================================================================

    /**
     * Run `$call` with every SDK call inside it sent under `$transactionId` — your own request
     * id, say — instead of an id per SDK call. GoPay logs it as the `X-GoPay-Transaction-Id` of
     * each request, and it comes back on errors as `$e->transactionId`.
     *
     * ```php
     * $payment = $sdk->withTransactionId($requestId, fn() => $sdk->createPayment($goid, $params));
     * ```
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @throws GoPaySdkException InvalidArgument when the id is not 1–64 characters of A–Z, a–z,
     *                           0–9, ".", "_" or "-" — what GoPay's log accepts
     *
     * @return T
     */
    public function withTransactionId(string $transactionId, callable $call): mixed
    {
        if (!Correlation::isValidTransactionId($transactionId)) {
            $this->http->emitError(new GoPaySdkException(
                '[GoPaySDK] transactionId must be 1–64 characters of A–Z, a–z, 0–9, ".", "_" or "-".',
                ErrorCode::InvalidArgument,
            ));
        }

        return $this->http->correlation()->inTransaction($call, $transactionId);
    }

    /**
     * One transaction id for everything a public call sends — its token refresh, retries and
     * polls — unless it runs inside another call or withTransactionId().
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function inTransaction(callable $call): mixed
    {
        return $this->http->correlation()->inTransaction($call);
    }

    // =========================================================================
    // Webhooks and events
    // =========================================================================

    /**
     * Check that a webhook comes from GoPay and return its event.
     * Requires the `shared:read` OAuth2 scope, for the signing keys (fetched once, kept by `kid`).
     *
     * Pass the body exactly as received — `file_get_contents('php://input')`, never a
     * re-encoded `json_decode()` — and the `X-GoPay-Signature` header
     * (`$_SERVER['HTTP_X_GOPAY_SIGNATURE'] ?? null`, or a PSR-7 `$request->getHeader(...)`).
     *
     * The event is the typed model of its `type` (e.g. `PaymentPaidEvent`), or an
     * {@see UnknownEvent} for a type added to the API after this SDK version: ignore it, as the
     * API asks, rather than failing.
     *
     * @param string|list<string>|null $signatureHeader
     *
     * Neither error reaches `onError`: anyone who can reach the endpoint can cause either, with a
     * forged request or a made-up key id. A failed fetch of the keys does, once per attempt.
     *
     * @throws GoPaySdkException `WebhookInvalid` (answer 4xx: not from GoPay, a retry carries the
     *                           same bytes) or `WebhookKeysUnavailable` (answer 5xx: not checked,
     *                           GoPay delivers it again)
     *
     * @return GoPayEvent
     */
    public function verifyWebhook(string $rawBody, string|array|null $signatureHeader): object
    {
        return $this->inTransaction(fn() => $this->webhooks->verifyWebhook($rawBody, $signatureHeader));
    }

    /**
     * The public keys GoPay signs webhooks with, as a JWK Set. {@see verifyWebhook()} fetches
     * them itself; this is for verifying elsewhere.
     * Requires the `shared:read` OAuth2 scope.
     *
     * GET /webhooks/public-keys
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getWebhookPublicKeys(): JWKSet
    {
        return $this->inTransaction(fn() => $this->webhooks->getWebhookPublicKeys());
    }

    /**
     * One page of the eshop's events, newest first; `getNextPageUrl()` is set while older ones
     * remain. Requires the `event:read` OAuth2 scope.
     *
     * GET /eshops/{goid}/events
     *
     * @param ListEventsParams $params Filters — `type` (`'payments.paid,refunds.*'`),
     *                                 `related_type` with `related_id`, `occurred_after`,
     *                                 `occurred_before` (a `DateTimeInterface` or an RFC 3339
     *                                 string) — and `max_items` (1–100)
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function listEvents(string $goid, array $params = []): EventPage
    {
        return $this->inTransaction(fn() => $this->events->listEvents($goid, $params));
    }

    /**
     * One event by its id, e.g. the `id` of a webhook. Requires the `event:read` OAuth2 scope.
     *
     * GET /eshops/{goid}/events/{event_id}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return GoPayEvent
     */
    public function getEvent(string $goid, string $eventId): object
    {
        return $this->inTransaction(fn() => $this->events->getEvent($goid, $eventId));
    }

    /**
     * Every event matching `$params`, page after page, fetching the next page only when the
     * previous one is used up. Requires the `event:read` OAuth2 scope.
     *
     * ```php
     * foreach ($sdk->iterateEvents($goid, ['related_type' => 'payments', 'related_id' => $paymentId]) as $event) { … }
     * ```
     *
     * @param ListEventsParams $params The filters of {@see listEvents()}
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     *
     * @return \Generator<int, GoPayEvent, mixed, void>
     */
    public function iterateEvents(string $goid, array $params = []): \Generator
    {
        // Taken now, not in the generator: its body first runs on iteration, outside this call.
        return $this->inTransactionOnEachStep(
            $this->events->iterateEvents($goid, $params),
            $this->http->correlation()->transactionId(),
        );
    }

    /**
     * A generator's body runs on each step, after the call that made it has returned: each step
     * is put back into the transaction the call was made in, so all its requests share one id.
     *
     * @template V
     *
     * @param \Generator<int, V, mixed, void> $inner
     *
     * @return \Generator<int, V, mixed, void>
     */
    private function inTransactionOnEachStep(\Generator $inner, string $transactionId): \Generator
    {
        $step = fn(callable $advance): mixed => $this->http->correlation()->inTransaction($advance, $transactionId);

        $step(static fn() => $inner->rewind());
        while ($step(static fn(): bool => $inner->valid())) {
            yield $inner->current();
            $step(static fn() => $inner->next());
        }
    }
}

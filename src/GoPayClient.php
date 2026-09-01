<?php

declare(strict_types=1);

namespace GoPay\Payments;

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Module\AuthApi;
use GoPay\Payments\Module\CardsApi;
use GoPay\Payments\Module\LinksApi;
use GoPay\Payments\Module\PaymentsApi;
use GoPay\Payments\Module\RefundsApi;
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
        $this->auth->authenticate($clientId, $clientSecret, $scope);
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
     * The returned `gw_url` is the escape hatch into the previous (v3) hosted
     * flow, for payment methods the v4 charge endpoint does not cover yet — a
     * supported alternative to createPayment() → chargePayment(), not a legacy
     * field. Drive one or the other for a given payment attempt, never both;
     * getPaymentStatus() reports the outcome either way.
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createPayment(string $goid, array $params): PaymentDetails
    {
        return $this->payments->createPayment($goid, $params);
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
        return $this->payments->getPaymentStatus($paymentId);
    }

    /**
     * Charge a payment using a payment instrument (card token, Apple Pay, Google Pay).
     *
     * POST /payments/{payment_id}/charge
     *
     * @param array<string, mixed> $params
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function chargePayment(string $paymentId, array $params): PaymentChargeResponse
    {
        return $this->payments->chargePayment($paymentId, $params);
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
        return $this->payments->getChargeState($paymentId);
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
        return $this->payments->getGooglePayInfo($paymentId);
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
        return $this->payments->getApplePayInfo($paymentId);
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
        return $this->payments->validateApplePayMerchant($paymentId, $body, $origin);
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
        return $this->payments->getQrPaymentInfo($paymentId, $format);
    }

    /**
     * Poll the charge state synchronously until a terminal outcome.
     * Throws CHARGE_FAILED on FAILED state, CHARGE_TIMEOUT if it doesn't settle.
     *
     * WARNING: This method calls usleep() in a loop and blocks the PHP process
     * for up to $timeoutSeconds. In PHP-FPM or mod_php deployments this holds a
     * worker unavailable to serve other requests for the full polling window.
     * Prefer a webhook-driven approach for production web applications: let the
     * GoPay notification call your server, then use getChargeState() once.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function awaitChargeState(
        string $paymentId,
        int $timeoutSeconds = 30,
        int $pollIntervalMs = 1_000,
    ): PaymentChargeStatusResponse {
        return $this->payments->awaitChargeState($paymentId, $timeoutSeconds, $pollIntervalMs);
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
        return $this->cards->getCardDetails($cardId);
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
        $this->cards->deleteCard($cardId);
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
        return $this->cards->tokenizeEncryptedCard($payload);
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
        return $this->refunds->refundPayment($paymentId, $params);
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
        return $this->refunds->listRefunds($paymentId);
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
        return $this->refunds->getRefund($refundId);
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
        return $this->refunds->awaitRefundState($refundId, $timeoutSeconds, $pollIntervalMs);
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
        return $this->links->createPaymentLink($goid, $params);
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
        return $this->links->linkStatus($goid, $linkId);
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
        $this->links->disableLink($goid, $linkId);
    }
}

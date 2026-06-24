<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\ChargeState;
use GoPay\Payments\Generated\Model\PaymentChargeResponse;
use GoPay\Payments\Generated\Model\PaymentChargeStatusResponse;
use GoPay\Payments\Generated\Model\PaymentDetails;
use GoPay\Payments\Generated\Model\QRPaymentDetails;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Http\RequestOptions;

/**
 * Payments module — create, charge, and inspect payments.
 *
 * Browser SDK compatibility note:
 *   The browser iframe tokenizes the card and yields a card token. Your server
 *   calls chargePayment() with that token. See CardsApi::tokenizeEncryptedCard()
 *   for the iframe JWE → permanent-token flow.
 *
 * IMPORTANT: createPayment() returns a `gw_url` field. Do NOT use or suggest it.
 * It exists for backward-compat with old redirect-based flows only. This SDK's
 * flow is always: createPayment() → chargePayment().
 */
final class PaymentsApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Create a new payment session.
     *
     * POST /eshops/{goid}/payments
     *
     * @param array<string, mixed> $params Payment creation parameters (amount, currency, order_number, customer, callback…).
     *
     * @throws GoPaySdkException
     */
    public function createPayment(string $goid, array $params): PaymentDetails
    {
        $gid = $this->requireNonEmpty($goid, 'goid');

        return $this->client->post("/eshops/{$gid}/payments", $params, PaymentDetails::class);
    }

    /**
     * Retrieve the current status of an existing payment.
     *
     * GET /payments/{payment_id}
     *
     * @throws GoPaySdkException
     */
    public function getPaymentStatus(string $paymentId): PaymentDetails
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->get("/payments/{$pid}", PaymentDetails::class);
    }

    /**
     * Charge a payment using a payment instrument (card token, Apple Pay, Google Pay).
     *
     * POST /payments/{payment_id}/charge
     *
     * @param array<string, mixed> $params Charge parameters including the payment_instrument.
     *
     * @throws GoPaySdkException
     */
    public function chargePayment(string $paymentId, array $params): PaymentChargeResponse
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->post("/payments/{$pid}/charge", $params, PaymentChargeResponse::class);
    }

    /**
     * Retrieve the current state of a payment charge.
     *
     * GET /payments/{payment_id}/charge
     *
     * @throws GoPaySdkException
     */
    public function getChargeState(string $paymentId): PaymentChargeStatusResponse
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->get("/payments/{$pid}/charge", PaymentChargeStatusResponse::class);
    }

    /**
     * Retrieve Google Pay configuration for this payment.
     * Returns a pre-filled paymentDataRequest ready for loadPaymentData().
     *
     * GET /payments/{payment_id}/google-pay/info
     *
     * @throws GoPaySdkException
     *
     * @return array<string, mixed>
     */
    public function getGooglePayInfo(string $paymentId): array
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->getArray("/payments/{$pid}/google-pay/info");
    }

    /**
     * Retrieve Apple Pay configuration for this payment.
     * Returns applepayVersion and applePayPaymentRequest for ApplePaySession construction.
     *
     * GET /payments/{payment_id}/apple-pay/info
     *
     * @throws GoPaySdkException
     *
     * @return array<string, mixed>
     */
    public function getApplePayInfo(string $paymentId): array
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->getArray("/payments/{$pid}/apple-pay/info");
    }

    /**
     * Validate the Apple Pay merchant during a web payment session (server-side).
     *
     * Forward the browser's validationURL to this method and pass the response
     * back to ApplePaySession.completeMerchantValidation().
     *
     * POST /payments/{payment_id}/apple-pay/validate
     *
     * @param array<string, mixed>|null $body Usually {validationUrl: "…"} from the browser.
     * @param string|null $origin Merchant HTTPS origin sent to Apple during validation.
     *
     * @throws GoPaySdkException
     *
     * @return array<string, mixed>
     */
    public function validateApplePayMerchant(
        string $paymentId,
        ?array $body = null,
        ?string $origin = null,
    ): array {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        $options = $origin !== null ? new RequestOptions(headers: ['Origin' => $origin]) : null;

        return $this->client->postArray("/payments/{$pid}/apple-pay/validate", $body, $options);
    }

    /**
     * Retrieve QR payment information (recipient details + base64 QR image).
     *
     * GET /payments/{payment_id}/qr-payment/info
     *
     * @param string|null $format Image format: 'png' (default) or 'svg'.
     *
     * @throws GoPaySdkException
     */
    public function getQrPaymentInfo(string $paymentId, ?string $format = null): QRPaymentDetails
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');
        if ($format !== null && !in_array($format, ['png', 'svg'], true)) {
            throw new GoPaySdkException('[GoPaySDK] format must be "png" or "svg".', ErrorCode::InvalidArgument);
        }
        $path = "/payments/{$pid}/qr-payment/info";
        if ($format !== null) {
            $path .= '?format=' . urlencode($format);
        }

        return $this->client->get($path, QRPaymentDetails::class);
    }

    /**
     * Poll the charge state synchronously until a terminal outcome.
     *
     * Resolves on SUCCEEDED. Throws GoPaySdkException with CHARGE_FAILED on
     * FAILED or CANCELLED state, or CHARGE_TIMEOUT if the charge does not settle
     * within $timeoutSeconds.
     *
     * @param int $timeoutSeconds Maximum total wait time (default 30 s).
     * @param int $pollIntervalMs Polling interval in milliseconds (default 1 000 ms).
     *
     * @throws GoPaySdkException
     */
    public function awaitChargeState(
        string $paymentId,
        int $timeoutSeconds = 30,
        int $pollIntervalMs = 1_000,
    ): PaymentChargeStatusResponse {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');
        if ($timeoutSeconds <= 0) {
            throw new GoPaySdkException('[GoPaySDK] timeoutSeconds must be > 0.', ErrorCode::InvalidArgument);
        }
        if ($pollIntervalMs <= 0) {
            throw new GoPaySdkException('[GoPaySDK] pollIntervalMs must be > 0.', ErrorCode::InvalidArgument);
        }
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $state = $this->client->get("/payments/{$pid}/charge", PaymentChargeStatusResponse::class);

            $chargeState = $state->getState();

            if ($chargeState === ChargeState::SUCCEEDED) {
                return $state;
            }

            if ($chargeState === ChargeState::FAILED) {
                throw new GoPaySdkException('[GoPaySDK] Charge failed.', ErrorCode::ChargeFailed);
            }

            if ($chargeState === ChargeState::CANCELLED) {
                throw new GoPaySdkException('[GoPaySDK] Charge cancelled.', ErrorCode::ChargeFailed);
            }

            if (time() >= $deadline) {
                throw new GoPaySdkException(
                    sprintf('[GoPaySDK] Charge timed out after %d seconds.', $timeoutSeconds),
                    ErrorCode::ChargeTimeout,
                );
            }

            $sleepUs = min($pollIntervalMs * 1_000, max(0, ($deadline - time()) * 1_000_000));
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }
    }
}

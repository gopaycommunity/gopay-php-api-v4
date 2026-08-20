<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\RefundDetails;
use GoPay\Payments\Generated\Model\RefundState;
use GoPay\Payments\Http\HttpClient;

/**
 * Refunds module — issue and inspect refunds.
 *
 * v4 note: Refunds are now a first-class resource with their own IDs. The v3
 * `refundPayment()` concept is retained but the API path changed from
 * POST /payments/payment/{id}/refund to POST /payments/{id}/refunds.
 */
final class RefundsApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Refund a payment fully or partially.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /payments/{payment_id}/refunds
     *
     * @param array<string, mixed> $params Refund parameters (amount in cents, etc.).
     *
     * @throws GoPaySdkException
     */
    public function refundPayment(string $paymentId, array $params): RefundDetails
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->post("/payments/{$pid}/refunds", $params, RefundDetails::class);
    }

    /**
     * List all refunds for a payment.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /payments/{payment_id}/refunds
     *
     * @throws GoPaySdkException
     *
     * @return list<RefundDetails>
     */
    public function listRefunds(string $paymentId): array
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return $this->client->getList("/payments/{$pid}/refunds", RefundDetails::class);
    }

    /**
     * Retrieve details of a single refund.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /refunds/{refund_id}
     *
     * @throws GoPaySdkException
     */
    public function getRefund(string $refundId): RefundDetails
    {
        $rid = $this->requireNonEmpty($refundId, 'refundId');

        return $this->client->get("/refunds/{$rid}", RefundDetails::class);
    }

    /**
     * Poll a refund until it settles.
     *
     * `refundPayment()` only ever returns `REQUESTED` — the refund is accepted, not
     * settled — so callers would otherwise write this loop themselves.
     *
     * Unlike {@see PaymentsApi::awaitChargeState()}, a `FAILED` refund is returned
     * rather than raised: the refundable amount is untouched, so the caller needs the
     * object to decide whether to retry. This also matches `awaitRefundState()` in the
     * JavaScript SDK, which resolves on `FAILED`.
     *
     * `$timeoutSeconds` bounds how long this keeps polling, not the wall clock at which
     * it returns: the deadline is only checked between polls, so a poll that comes back
     * terminal is returned even if the deadline elapsed while it was in flight, since a
     * real answer beats raising a timeout over one that already arrived. Same semantics
     * as {@see PaymentsApi::awaitChargeState()}.
     *
     * @param int $timeoutSeconds Maximum total wait time (default 30 s).
     * @param int $pollIntervalMs Polling interval in milliseconds (default 1 000 ms).
     *
     * @throws GoPaySdkException
     */
    public function awaitRefundState(
        string $refundId,
        int $timeoutSeconds = 30,
        int $pollIntervalMs = 1_000,
    ): RefundDetails {
        $rid = $this->requireNonEmpty($refundId, 'refundId');
        if ($timeoutSeconds <= 0) {
            throw new GoPaySdkException('[GoPaySDK] timeoutSeconds must be > 0.', ErrorCode::InvalidArgument);
        }
        if ($pollIntervalMs <= 0) {
            throw new GoPaySdkException('[GoPaySDK] pollIntervalMs must be > 0.', ErrorCode::InvalidArgument);
        }
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $refund = $this->client->get("/refunds/{$rid}", RefundDetails::class);
            $state  = $refund->getState();

            if ($state === RefundState::SUCCESS || $state === RefundState::FAILED) {
                return $refund;
            }

            $now = time();
            if ($now >= $deadline) {
                $this->client->emitError(new GoPaySdkException(
                    sprintf('[GoPaySDK] Refund did not settle within %d seconds.', $timeoutSeconds),
                    ErrorCode::ChargeTimeout,
                ));
            }

            $sleepUs = min($pollIntervalMs * 1_000, max(0, ($deadline - $now) * 1_000_000));
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }
    }
}

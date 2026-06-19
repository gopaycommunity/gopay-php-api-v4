<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\RefundDetails;
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
     * @return list<RefundDetails>
     * @throws GoPaySdkException
     */
    public function listRefunds(string $paymentId): array
    {
        $pid = $this->requireNonEmpty($paymentId, 'paymentId');

        return array_map(
            static fn (array $item): RefundDetails => RefundDetails::fromArray($item),
            $this->client->getJsonList("/payments/{$pid}/refunds"),
        );
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

    private function requireNonEmpty(string $value, string $paramName): string
    {
        if ($value === '') {
            throw new GoPaySdkException("[GoPaySDK] {$paramName} must not be empty.", ErrorCode::InvalidArgument);
        }

        return $value;
    }
}

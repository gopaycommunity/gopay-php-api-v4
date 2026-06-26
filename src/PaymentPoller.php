<?php

declare(strict_types=1);

namespace GoPay\Payments;

use GoPay\Payments\Generated\Model\PaymentState;

/**
 * Helpers for polling payment state in server-side or webhook-driven flows.
 *
 * The payment lifecycle has two groups of states:
 *
 *   Pending  — payment is still in progress; keep polling:
 *     CREATED, PAYMENT_METHOD_CHOSEN
 *
 *   Terminal — payment has reached a final state; stop polling:
 *     PAID               → success
 *     AUTHORIZED         → success (captured separately)
 *     CANCELED           → failure (customer or merchant cancelled)
 *     TIMEOUTED          → failure (not completed within the gateway time limit)
 *     REFUNDED           → post-success (fully refunded)
 *     PARTIALLY_REFUNDED → post-success (partially refunded)
 *
 * Usage with GoPayClient::getPaymentStatus():
 * ```php
 * do {
 *     sleep(3);
 *     $payment = $sdk->getPaymentStatus($paymentId);
 * } while (PaymentPoller::isPending($payment->getState()));
 *
 * $succeeded = PaymentPoller::isSuccessful($payment->getState());
 * ```
 */
final class PaymentPoller
{
    /** States that indicate the payment is still in progress. */
    private const PENDING = [
        PaymentState::CREATED,
        PaymentState::PAYMENT_METHOD_CHOSEN,
    ];

    /** Terminal states that signal success. */
    private const SUCCESSFUL = [
        PaymentState::PAID,
        PaymentState::AUTHORIZED,
    ];

    /** Terminal states that signal failure. */
    private const FAILED = [
        PaymentState::CANCELED,
        PaymentState::TIMEOUTED,
    ];

    /** Returns true while the payment needs more time — keep polling. */
    public static function isPending(string $state): bool
    {
        return in_array($state, self::PENDING, true);
    }

    /** Returns true once the payment has reached any final state — stop polling. */
    public static function isTerminal(string $state): bool
    {
        return !self::isPending($state);
    }

    /** Returns true when the payment completed successfully. */
    public static function isSuccessful(string $state): bool
    {
        return in_array($state, self::SUCCESSFUL, true);
    }

    /** Returns true when the payment ended without success (cancelled, timed out). */
    public static function isFailed(string $state): bool
    {
        return in_array($state, self::FAILED, true);
    }
}

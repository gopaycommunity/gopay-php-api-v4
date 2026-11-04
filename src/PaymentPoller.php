<?php

declare(strict_types=1);

namespace GoPay\Payments;

use GoPay\Payments\Generated\Model\PaymentState;

/**
 * Helpers for reading payment state in server-side or webhook-driven flows.
 *
 * The payment lifecycle has three groups of states:
 *
 *   Created  — nothing is running yet: no payment method has been chosen. Neither pending nor
 *              terminal — the customer may still pay, or never start.
 *     CREATED
 *
 *     A QR payment today stays CREATED until the transfer is credited. Do not rely on that:
 *     wait for a terminal state (or the payments.paid webhook) instead.
 *
 *   Pending  — the payment is in progress; keep polling:
 *     PAYMENT_METHOD_CHOSEN
 *
 *   Terminal — the payment has reached a final state; stop polling:
 *     PAID               → success
 *     AUTHORIZED         → success (captured separately)
 *     CANCELED           → failure (customer or merchant cancelled)
 *     TIMEOUTED          → failure (not completed within the gateway time limit)
 *     REFUNDED           → post-success (fully refunded)
 *     PARTIALLY_REFUNDED → post-success (partially refunded)
 *
 * A state the API adds later is in none of the groups.
 *
 * Usage with GoPayClient::getPaymentStatus():
 * ```php
 * $deadline = time() + 30;
 * do {
 *     $payment = $sdk->getPaymentStatus($paymentId);
 * } while (PaymentPoller::isPending($payment->getState()) && time() < $deadline && sleep(2) === 0);
 *
 * $succeeded = PaymentPoller::isSuccessful($payment->getState());
 * $notStarted = PaymentPoller::isCreated($payment->getState());
 * ```
 */
final class PaymentPoller
{
    /** States that indicate the payment is in progress. */
    private const PENDING = [
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

    /** Every final state: the successful, the failed and the post-success ones. */
    private const TERMINAL = [
        ...self::SUCCESSFUL,
        ...self::FAILED,
        PaymentState::REFUNDED,
        PaymentState::PARTIALLY_REFUNDED,
    ];

    /**
     * Returns true while nothing is running: the payment exists, but no payment method has been
     * chosen. Not pending — polling will not see it move unless the customer acts.
     */
    public static function isCreated(string $state): bool
    {
        return $state === PaymentState::CREATED;
    }

    /** Returns true while the payment is in progress — keep polling. CREATED is not pending. */
    public static function isPending(string $state): bool
    {
        return in_array($state, self::PENDING, true);
    }

    /** Returns true once the payment has reached a final state — stop polling. */
    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
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

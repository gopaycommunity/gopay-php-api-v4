<?php

declare(strict_types=1);

/**
 * Refund a payment and follow the refund to a terminal state.
 *
 * Usage: php examples/refund-payment.php <payment_id> [amount_in_minor_units]
 *
 * With no amount the remaining refundable amount is refunded — which for a card
 * payment is the whole payment until its transaction has been processed by the
 * acquirer, since a partial refund before that is rejected.
 *
 * Refunds need the `payment:write` scope, so this is a server-side flow only —
 * a payment-scoped browser token cannot issue one.
 *
 * Amounts are in minor units (e.g. 10000 = 100.00 CZK).
 *
 * Exit codes: 0 the refund reached SUCCESS, 1 it was rejected or reached FAILED,
 * 2 it was accepted but still REQUESTED when polling gave up.
 */

require __DIR__ . '/bootstrap.php';

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\RefundState;

const REFUNDABLE_STATES = ['PAID', 'PARTIALLY_REFUNDED'];
const POLL_ATTEMPTS     = 10;
const POLL_SLEEP        = 3;

$paymentId = $argv[1] ?? null;
if ($paymentId === null) {
    fwrite(STDERR, "Usage: php examples/refund-payment.php <payment_id> [amount]\n");
    exit(1);
}

/**
 * Print the gateway's own message — "GoPay API error: HTTP 400" alone says nothing.
 */
function describe(GoPayHttpException $e): string
{
    $body = $e->body;

    return is_array($body) && isset($body['message']) ? (string) $body['message'] : $e->getMessage();
}

try {
    $payment = $sdk->getPaymentStatus($paymentId);
    $state   = (string) $payment->getState();

    printf(
        "Payment %s: state=%s amount=%d %s%s",
        (string) $payment->getId(),
        $state,
        (int) $payment->getAmount(),
        (string) $payment->getCurrency(),
        PHP_EOL,
    );

    // Only PAID or PARTIALLY_REFUNDED is refundable; anything else is a guaranteed 409.
    if (!in_array($state, REFUNDABLE_STATES, true)) {
        fwrite(STDERR, sprintf(
            'Payment is %s — only %s can be refunded.%s',
            $state,
            implode(' or ', REFUNDABLE_STATES),
            PHP_EOL,
        ));
        exit(1);
    }

    // Default to what is left, not the original amount: a PARTIALLY_REFUNDED payment
    // would otherwise always be asked for more than it can still return.
    $alreadyRefunded = 0;
    foreach ($sdk->listRefunds($paymentId) as $existing) {
        if ((string) $existing->getState() === RefundState::SUCCESS) {
            $alreadyRefunded += (int) $existing->getAmount();
        }
    }
    $remaining = (int) $payment->getAmount() - $alreadyRefunded;

    if (isset($argv[2]) && $argv[2] !== '') {
        // Reject locally what the API would reject anyway, with a clearer message.
        if (!ctype_digit($argv[2])) {
            fwrite(STDERR, sprintf(
                'Amount must be a positive whole number of minor units, got "%s".%s',
                $argv[2],
                PHP_EOL,
            ));
            exit(1);
        }
        $amount = (int) $argv[2];
    } else {
        $amount = $remaining;
    }

    if ($amount < 1) {
        fwrite(STDERR, sprintf('Amount must be at least 1 minor unit, got %d.%s', $amount, PHP_EOL));
        exit(1);
    }

    if ($amount > $remaining) {
        fwrite(STDERR, sprintf(
            'Amount %d exceeds the %d still refundable on this payment.%s',
            $amount,
            $remaining,
            PHP_EOL,
        ));
        exit(1);
    }

    printf('Refunding %d of %d remaining (minor units).%s', $amount, $remaining, PHP_EOL);

    try {
        $refund = $sdk->refundPayment($paymentId, ['amount' => $amount]);
    } catch (GoPayHttpException $e) {
        // 400 — amount was not positive.
        // 409 — payment is not refundable, or a partial refund was attempted before
        //       the card transaction had been processed by the acquirer.
        fwrite(STDERR, sprintf('Refund rejected (HTTP %d): %s%s', $e->status, describe($e), PHP_EOL));
        exit(1);
    }

    printf(
        "Refund %s created: state=%s amount=%d%s",
        (string) $refund->getId(),
        (string) $refund->getState(),
        (int) $refund->getAmount(),
        PHP_EOL,
    );

    // Refunds are asynchronous: the 201 only means "accepted".
    $refundId = (string) $refund->getId();
    $settled  = false;
    for ($attempt = 1; $attempt <= POLL_ATTEMPTS; $attempt++) {
        sleep(POLL_SLEEP);
        $current   = $sdk->getRefund($refundId);
        $currState = (string) $current->getState();
        printf('  poll %d: %s%s', $attempt, $currState, PHP_EOL);
        if ($currState === RefundState::SUCCESS) {
            $settled = true;
            break;
        }
        if ($currState === RefundState::FAILED) {
            // Terminal, but not a settlement — never report this as success.
            fwrite(STDERR, sprintf('Refund %s FAILED.%s', $refundId, PHP_EOL));
            exit(1);
        }
    }

    if (!$settled) {
        printf(
            'Still REQUESTED after %d polls — refund %s has not settled yet.%s',
            POLL_ATTEMPTS,
            $refundId,
            PHP_EOL,
        );
    }

    echo PHP_EOL . 'All refunds on this payment:' . PHP_EOL;
    foreach ($sdk->listRefunds($paymentId) as $item) {
        printf(
            "  %s  %-9s %d %s%s",
            (string) $item->getId(),
            (string) $item->getState(),
            (int) $item->getAmount(),
            (string) $item->getCurrency(),
            PHP_EOL,
        );
    }

    printf('Payment state after refund: %s%s', (string) $sdk->getPaymentStatus($paymentId)->getState(), PHP_EOL);

    exit($settled ? 0 : 2);
} catch (GoPayHttpException $e) {
    fwrite(STDERR, sprintf('API error (HTTP %d): %s%s', $e->status, describe($e), PHP_EOL));
    exit(1);
} catch (GoPaySdkException $e) {
    fwrite(STDERR, sprintf('SDK error: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}

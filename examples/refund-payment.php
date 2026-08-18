<?php

declare(strict_types=1);

/**
 * Refund a payment and follow the refund to a terminal state.
 *
 * Usage: php examples/refund-payment.php <payment_id> [amount_in_minor_units]
 *
 * With no amount the payment is refunded in full, which is the only option a card
 * payment allows until its transaction has been processed by the acquirer.
 *
 * Refunds need the `payment:write` scope, so this is a server-side flow only —
 * a payment-scoped browser token cannot issue one.
 */

require __DIR__ . '/bootstrap.php';

use GoPay\Payments\Exception\GoPayHttpException;

$paymentId = $argv[1] ?? null;
if ($paymentId === null) {
    fwrite(STDERR, "Usage: php examples/refund-payment.php <payment_id> [amount]\n");
    exit(1);
}

$payment = $sdk->getPaymentStatus($paymentId);
printf(
    "Payment %s: state=%s amount=%d %s%s",
    $payment->getId(),
    (string) $payment->getState(),
    (int) $payment->getAmount(),
    (string) $payment->getCurrency(),
    PHP_EOL,
);

// Only a PAID or PARTIALLY_REFUNDED payment can be refunded; anything else is a 409.
$amount = isset($argv[2]) && $argv[2] !== ''
    ? (int) $argv[2]
    : (int) $payment->getAmount();

try {
    $refund = $sdk->refundPayment($paymentId, ['amount' => $amount]);
} catch (GoPayHttpException $e) {
    // 400 — amount was not positive.
    // 409 — payment is not refundable, or a partial refund was attempted before
    //       the card transaction had been processed by the acquirer.
    $body   = $e->body;
    $detail = is_array($body) && isset($body['message']) ? (string) $body['message'] : $e->getMessage();
    fwrite(STDERR, sprintf('Refund rejected (HTTP %d): %s%s', $e->status, $detail, PHP_EOL));
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
for ($attempt = 0; $attempt < 10; $attempt++) {
    sleep(3);
    $current = $sdk->getRefund($refundId);
    $state   = (string) $current->getState();
    printf("  poll %d: %s%s", $attempt + 1, $state, PHP_EOL);
    if ($state !== 'REQUESTED') {
        break;
    }
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

$after = $sdk->getPaymentStatus($paymentId);
printf('Payment state after refund: %s%s', (string) $after->getState(), PHP_EOL);

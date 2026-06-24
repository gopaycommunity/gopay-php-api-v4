<?php

declare(strict_types=1);

/**
 * Core flow: create a payment, charge it with a card token, await settlement.
 *
 * Run: php examples/create-and-charge.php
 *
 * The card token used here is the value the browser SDK's hosted iframe returns
 * after the customer enters their card details. In a real integration the token
 * comes from the browser (see browser-sdk-integration.php); here we use a
 * Stoplight mock token so the script is self-contained and never charges a card.
 */

require __DIR__ . '/bootstrap.php';

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;

// ─── Step 1: Create the payment session ──────────────────────────────────────

echo PHP_EOL . '=== Step 1: createPayment ===' . PHP_EOL;

$payment = $sdk->createPayment($goid, [
    'amount'       => 1990,        // minor units — 19.90 CZK
    'currency'     => 'CZK',
    'order_number' => 'ORDER-' . date('YmdHis'),
    'customer'     => [
        'email'      => 'customer@example.com',
        'first_name' => 'Jan',
        'last_name'  => 'Novák',
    ],
    'callback' => [
        'notification_url' => 'https://yourshop.example.com/gopay/notify',
        'return_url'       => 'https://yourshop.example.com/gopay/return',
    ],
]);

// Do NOT use $payment->gw_url — it is a backward-compat field for old redirects.
// This SDK always uses createPayment() → chargePayment().

echo 'Payment ID : ' . $payment->getId() . PHP_EOL;
echo 'State      : ' . ($payment->getState() ?? 'n/a') . PHP_EOL;

// ─── Step 2: Charge with a card token ────────────────────────────────────────
//
// In production the token is produced by the GoPay-hosted iframe in the browser:
//   const { token } = await gopayBrowserSdk.mountCardForm(...)
// That token is POSTed to your server, which calls chargePayment() below.
//
// For this example we use a placeholder token; the mock API accepts any value.

echo PHP_EOL . '=== Step 2: chargePayment ===' . PHP_EOL;

$cardToken = 'tok_mocktoken_example_12345';

try {
    $charge = $sdk->chargePayment($payment->getId(), [
        'payment_instrument' => [
            'payment_instrument' => 'PAYMENT_CARD',
            'input'              => [
                'input_type' => 'CARD_TOKEN',
                'card_token' => $cardToken,
            ],
            'browser_data'       => [
                'language'            => 'en-US',
                'timezone'            => 0,
                'screen_width'        => 1920,
                'screen_height'       => 1080,
                'color_depth'         => 24,
                'user_agent'          => 'Mozilla/5.0 (example)',
                'accept_header'       => 'text/html',
                'javascript_enabled'  => true,
            ],
        ],
    ]);
} catch (GoPayHttpException $e) {
    echo 'Charge failed  : HTTP ' . $e->status . ' — ' . json_encode($e->body) . PHP_EOL;
    exit(1);
}

echo 'Charge state   : ' . ($charge->getState() ?? 'n/a') . PHP_EOL;

// If the card requires 3-D Secure verification the API returns a redirect_url.
// Redirect the customer to that URL; they complete 3DS on the bank's page and
// the bank redirects back to your callback.return_url.
if ($charge->getAction()?->getRedirectUrl() !== null) {
    echo '3DS redirect   : ' . $charge->getAction()->getRedirectUrl() . PHP_EOL;
    echo '→ Redirect the customer to the URL above, then poll getPaymentStatus().' . PHP_EOL;
    exit(0);
}

// ─── Step 3: Await settlement (no 3DS) ───────────────────────────────────────

echo PHP_EOL . '=== Step 3: awaitChargeState ===' . PHP_EOL;

try {
    $final = $sdk->awaitChargeState($payment->getId(), timeoutSeconds: 10);
    echo 'Final state    : ' . ($final->getState() ?? 'n/a') . PHP_EOL;
} catch (GoPaySdkException $e) {
    echo 'Charge outcome : ' . $e->getMessage() . PHP_EOL;
}

// ─── Step 4: Read the payment record ─────────────────────────────────────────

echo PHP_EOL . '=== Step 4: getPaymentStatus ===' . PHP_EOL;

$status = $sdk->getPaymentStatus($payment->getId());
echo 'Payment state  : ' . ($status->getState() ?? 'n/a') . PHP_EOL;
echo 'Amount         : ' . ($status->getAmount() ?? 'n/a') . ' ' . ($status->getCurrency() ?? '') . PHP_EOL;

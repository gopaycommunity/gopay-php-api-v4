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
 * placeholder token — replace it with a real token from the sandbox iframe.
 */

require __DIR__ . '/bootstrap.php';

use GoPay\Payments\AcceptHeader;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;

// ─── Step 1: Create the payment session ──────────────────────────────────────

echo PHP_EOL . '=== Step 1: createPayment ===' . PHP_EOL;

// `amount` is minor units ("cents"), not major units — 1990 means 19.90 CZK.
// Every currency this API accepts (CZK, EUR, PLN, USD, GBP, HUF, RON) uses a
// two-decimal minor unit, so `* 100` is arithmetically right today. Put it in
// one toMinorUnit(major, currency) helper anyway instead of inline at each
// call site: rounding a float price is where this actually goes wrong.
//
// GoPay has no idempotency key for createPayment() — a double-submit or a
// client retry after a timeout calls this endpoint again with nothing
// stopping GoPay from creating a second, separate payment (new id, new
// gw_url) for the same order. A real create-payment route must check for an
// existing pending/paid order with this order_number *first* and return that
// payment's details instead of calling createPayment() again. This example
// always mints a fresh timestamp-based order_number so it's safe to re-run,
// but that dedup check is not optional in production.
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

// $payment->gw_url also exists here — it's a deliberate, still-supported
// escape hatch into the previous (v3) hosted-gateway flow, for payment methods
// the v4 charge endpoint does not yet cover, not a legacy field to avoid.
// Redirecting to it hands off real-time control of this payment to the hosted
// flow while the customer is on it, but getPaymentStatus() still reports the
// final state once they complete it — exactly as it would for a payment
// charged directly through v4. This example demonstrates the create → charge
// (card-only) flow, so gw_url is simply unused below; the only rule is not to
// redirect to it *and* call chargePayment() for the same payment attempt.

echo 'Payment ID : ' . $payment->getId() . PHP_EOL;
echo 'State      : ' . ($payment->getState() ?? 'n/a') . PHP_EOL;

// ─── Step 2: Charge with a card token ────────────────────────────────────────
//
// In production the token is produced by the GoPay-hosted iframe in the browser:
//   const { token } = await gopayBrowserSdk.mountCardForm(...)
// That token is POSTed to your server, which calls chargePayment() below.
//
// Replace with a real token from the GoPay-hosted iframe (browser SDK).

echo PHP_EOL . '=== Step 2: chargePayment ===' . PHP_EOL;

$cardToken = 'tok_placeholder_replace_with_real';

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
                // accept_header is REQUIRED: the JSON-encoded Accept headers of the
                // *customer's* browser, captured server-side from the request the
                // browser made to you. In a real handler just call
                //   AcceptHeader::fromServerGlobals()          — reads $_SERVER
                //   AcceptHeader::fromServerRequest($request)  — PSR-7 alternative
                // This CLI script has no incoming browser request, so we feed the
                // helper a $_SERVER-shaped sample instead.
                'accept_header'       => AcceptHeader::fromServerGlobals([
                    'HTTP_ACCEPT'          => 'application/json, text/plain, */*',
                    'HTTP_ACCEPT_LANGUAGE' => 'cs;q=0.5',
                    'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br, zstd',
                ]),
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

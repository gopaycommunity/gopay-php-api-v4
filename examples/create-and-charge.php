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

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\ChargeState;

// ─── Step 1: Create the payment session ──────────────────────────────────────

echo PHP_EOL . '=== Step 1: createPayment ===' . PHP_EOL;

// `amount` is minor units ("cents"), not major units — 1990 means 19.90 CZK.
// Convert in one toMinorUnit(major, currency) helper rather than inline at each
// call site: rounding a float price is where this usually goes wrong, and the
// exponent is per-currency. The spec says only "amount in cents" and does not
// state exponents, so confirm HUF with the gateway before assuming `* 100` —
// ISO 4217 gives it two decimals but payment systems often treat it as zero.
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

// $payment->gw_url also exists here — it is the address of GoPay's hosted
// payment gateway, which offers the customer every method the eshop has
// enabled. It's a supported integration in its own right, not a legacy field
// to avoid; you just can't embed it in your own checkout, which is why this
// example doesn't send the customer there. Redirecting to it hands the payment
// over to the gateway while the customer is on it, but getPaymentStatus()
// still reports the final state once they complete it — exactly as it would
// for a payment charged directly through v4. This example demonstrates the
// create → charge (card-only) flow, so gw_url is simply unused below; the only
// rule is not to redirect to it *and* call chargePayment() for the same
// payment attempt.

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
                'javascript_enabled'  => true,
                // ip, user_agent and accept_header are the three fields the browser
                // cannot determine about itself. All three are REQUIRED, and all three
                // must come from GET /cards/browser-data called *by the customer's
                // browser* (client_id:shareable_key auth) right before the charge; the
                // browser POSTs them to you along with the rest of browser_data.
                //
                // Do not fill them in from the request your server received. A
                // server-side call to that endpoint returns the server's own address
                // and headers, and the card issuer rejects those during 3-D Secure.
                // That is also why this SDK exposes no method for the endpoint.
                //
                // This CLI script has no browser behind it, so these are placeholders.
                'ip'                  => '192.0.2.42',
                'user_agent'          => 'Mozilla/5.0 (example)',
                'accept_header'       => '{"accept":"application/json, text/plain, */*"}',
            ],
        ],
    ]);
} catch (GoPayHttpException $e) {
    echo 'Charge failed  : HTTP ' . $e->status . ' — ' . json_encode($e->body) . PHP_EOL;
    exit(1);
}

echo 'Charge state   : ' . ($charge->getState() ?? 'n/a') . PHP_EOL;

// ─── Step 3: Await the charge decision ───────────────────────────────────────
//
// The POST response is not the outcome: the charge runs asynchronously and is
// usually still PROCESSING. A 3-D Secure challenge rarely shows up in it — it
// appears when polling GET /charge a moment later, as ACTION_REQUIRED with a
// redirect URL. awaitChargeState() polls until SUCCEEDED or ACTION_REQUIRED and
// throws on FAILED or timeout.

echo PHP_EOL . '=== Step 3: awaitChargeState ===' . PHP_EOL;

try {
    $final = $sdk->awaitChargeState($payment->getId(), timeoutSeconds: 10);
    echo 'Final state    : ' . ($final->getState() ?? 'n/a') . PHP_EOL;

    // On ACTION_REQUIRED redirect the customer to the URL; they complete 3DS on
    // the bank's page and the bank redirects back to your callback.return_url.
    //
    // Send them there IMMEDIATELY. The URL opens an authentication session held
    // open by the bank, and it times out quickly — a customer who arrives late
    // finds the payment already cancelled. Order bookkeeping, emails and anything
    // else slow belong in the return_url handler or the notification_url webhook,
    // never between reading this URL and the redirect.
    if ($final->getState() === ChargeState::ACTION_REQUIRED) {
        echo '3DS redirect   : ' . ($final->getAction()?->getRedirectUrl() ?? 'n/a') . PHP_EOL;
        echo '→ Redirect the customer to the URL above, then poll getPaymentStatus().' . PHP_EOL;
        exit(0);
    }
} catch (GoPaySdkException $e) {
    echo 'Charge outcome : ' . $e->getMessage() . PHP_EOL;
}

// ─── Step 4: Read the payment record ─────────────────────────────────────────

echo PHP_EOL . '=== Step 4: getPaymentStatus ===' . PHP_EOL;

$status = $sdk->getPaymentStatus($payment->getId());
echo 'Payment state  : ' . ($status->getState() ?? 'n/a') . PHP_EOL;
echo 'Amount         : ' . ($status->getAmount() ?? 'n/a') . ' ' . ($status->getCurrency() ?? '') . PHP_EOL;

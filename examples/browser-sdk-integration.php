<?php

declare(strict_types=1);

/**
 * Browser SDK integration pattern — server side.
 *
 * Shows the two-part seam between the PHP server and the GoPay browser iframe:
 *
 *   [Browser]                           [PHP Server]
 *    gopayBrowserSdk.mountCardForm()
 *    → customer enters card details
 *    → iframe calls POST /cards/tokens
 *    → { token, card_id }              ←─ or ─→
 *    → POST /your-server/charge               tokenizeEncryptedCard(jwePayload)
 *                                        chargePayment(paymentId, { card_token: token })
 *
 * Run: php examples/browser-sdk-integration.php
 */

require __DIR__ . '/bootstrap.php';

// ─── Provide browser SDK keys to the front-end ───────────────────────────────
//
// The server passes these two values to the HTML page (e.g. via a /config endpoint
// or embedded in the page template). The browser SDK uses them to initialise the
// hosted card iframe. Never expose client_secret to the browser.

echo PHP_EOL . '=== Browser SDK init keys (embed in your page) ===' . PHP_EOL;

$browserKeys = $sdk->getBrowserKeys();
echo 'client_id     : ' . $browserKeys['client_id'] . PHP_EOL;
echo 'shareable_key : ' . $browserKeys['shareable_key'] . PHP_EOL;

// Example JS (embed in your page template):
// <script>
// const sdk = createGoPayBrowserSDK({
//     clientId:     '<?= $browserKeys["client_id"] ?>',
//     shareableKey: '<?= $browserKeys["shareable_key"] ?>',
//     environment:  'sandbox',
// });
// </script>

// ─── JWE flow (iframe in return-payload mode) ─────────────────────────────────
//
// When the iframe is configured with returnPayload: true, the browser receives
// a JWE payload instead of calling POST /cards/tokens directly.
// Your server receives the JWE via a form POST or fetch, then calls:

echo PHP_EOL . '=== JWE tokenisation (iframe return-payload mode) ===' . PHP_EOL;

$jwePayload = '...' /* sent by the browser */;

// (Skipped in this example — requires a real JWE from the iframe)
echo '(Skipped — requires a real JWE payload from the browser iframe)' . PHP_EOL;
echo 'In production:' . PHP_EOL;
echo '  $tokenDetails = $sdk->tokenizeEncryptedCard($jwePayload);' . PHP_EOL;
echo '  $token = $tokenDetails->token;' . PHP_EOL;
echo '  $sdk->chargePayment($paymentId, [' . PHP_EOL;
echo '      "payment_instrument" => [' . PHP_EOL;
echo '          "payment_instrument" => "PAYMENT_CARD",' . PHP_EOL;
echo '          "input" => ["input_type" => "CARD_TOKEN", "card_token" => $token],' . PHP_EOL;
echo '      ],' . PHP_EOL;
echo '  ]);' . PHP_EOL;

// ─── Standard iframe flow (iframe calls POST /cards/tokens itself) ────────────
//
// When the iframe calls POST /cards/tokens directly, the browser receives
// { token, card_id } and posts it to your server endpoint, e.g. /charge.
// Your server reads the token and calls chargePayment().

echo PHP_EOL . '=== Standard card-token flow ===' . PHP_EOL;
echo 'Token arrives from browser → $sdk->chargePayment($paymentId, [...card_token...])' . PHP_EOL;
echo 'See create-and-charge.php for the full chargePayment() call.' . PHP_EOL;

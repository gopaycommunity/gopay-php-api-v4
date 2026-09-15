# GoPay PHP SDK — Payments API v4

[![Packagist Version](https://img.shields.io/packagist/v/gopaycommunity/gopay-php-api-v4)](https://packagist.org/packages/gopaycommunity/gopay-php-api-v4)
[![PHP Version](https://img.shields.io/packagist/php-v/gopaycommunity/gopay-php-api-v4)](https://packagist.org/packages/gopaycommunity/gopay-php-api-v4)
[![License](https://img.shields.io/packagist/l/gopaycommunity/gopay-php-api-v4)](https://packagist.org/packages/gopaycommunity/gopay-php-api-v4)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=gp-gopay_gopay-php-api-v4&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=gp-gopay_gopay-php-api-v4)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=gp-gopay_gopay-php-api-v4&metric=coverage)](https://sonarcloud.io/summary/new_code?id=gp-gopay_gopay-php-api-v4)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%2010-brightgreen)](phpstan.neon)

Server-side PHP SDK for new [GoPay Payments API v4](https://api-docs.gopay.com/).

Requires **PHP ≥ 8.1**. Transport-agnostic — works with any PSR-18 HTTP client.

---

## v3 → v4 migration

See [MIGRATION.md](MIGRATION.md) for a full breakdown. The SDK is v4-only — not source-level compatible with `gopay/payments-sdk` v1.

Key changes:
- **Gateway URL changed** — update your `Config` initialization
- **Redirecting to `gw_url` is no longer the default flow** — replace `header('Location: gw_url')` with `chargePayment()`. `gw_url` itself is not legacy and has not gone anywhere: it still opens GoPay's hosted gateway, which is a supported integration in its own right. 3DS challenges still redirect via `getAction()->getRedirectUrl()`
- **Recurrences redesigned** — a recurrence is now a standalone entity with its own ID, not something attached to a payment. See [Recurrences](#recurrences)
- **9 v3 methods removed** — pre-auth capture/void, EET, account statement, payment instruments. Refunds and recurrences are back in v4 on new paths, see [Refunds](#refunds) and [Recurrences](#recurrences)

---

## Installation

```bash
composer require gopaycommunity/gopay-php-api-v4
```

For the HTTP client you need a PSR-18 implementation. Guzzle 7 is the most common choice:

```bash
composer require guzzlehttp/guzzle
```

Any PSR-18-compatible client works (Symfony HttpClient, Buzz, …).

---

## Quick start

```php
use GoPay\Payments\GoPayClient;
use GoPay\Payments\Config;
use GoPay\Payments\Environment;

// 1. Initialize the client
$sdk = new GoPayClient(new Config(
    environment: Environment::Sandbox,
    shareableKey: 'YOUR_SHAREABLE_KEY', // optional — for browser SDK initialisation
));

// 2. Authenticate (stored internally; token refreshes automatically)
$sdk->authenticate('YOUR_CLIENT_ID', 'YOUR_CLIENT_SECRET', 'payment:write payment:read');

// 3. Create a payment
$payment = $sdk->createPayment('YOUR_GOID', [
    'amount'       => 1000,             // 10.00 CZK (in minor units / haléře)
    'currency'     => 'CZK',
    'order_number' => 'ORDER-001',
    'customer'     => ['email' => 'customer@example.com'],
    'callback'     => [
        'notification_url' => 'https://yourshop.com/notify',
        'return_url'       => 'https://yourshop.com/return',
    ],
]);

// 4. Charge using a card token from the browser iframe
//    (browser SDK's mountCardForm() → user enters card → iframe returns token)
$charge = $sdk->chargePayment($payment->getId(), [
    'payment_instrument' => [
        'payment_instrument' => 'PAYMENT_CARD',
        'input' => [
            'input_type' => 'CARD_TOKEN',
            'card_token' => $cardToken, // from the browser SDK iframe
        ],
        'browser_data' => [
            // EMV 3DS device data — collect in the browser, POST to your server.
            'language'           => $browserData['language'],      // e.g. 'cs-CZ'
            'timezone'           => $browserData['timezone'],      // e.g. -60
            'screen_width'       => $browserData['screen_width'],  // e.g. 1920
            'screen_height'      => $browserData['screen_height'], // e.g. 1080
            'color_depth'        => $browserData['color_depth'],   // e.g. 24
            'javascript_enabled' => $browserData['javascript_enabled'], // e.g. true
            // ip, user_agent and accept_header are the three the browser cannot
            // determine itself. The browser fetches them from
            // GET /cards/browser-data (shareable_key auth) right before the charge
            // and POSTs them to you with the rest — see the note below.
            'ip'                 => $browserData['ip'],            // e.g. '192.0.2.42'
            'user_agent'         => $browserData['user_agent'],
            'accept_header'      => $browserData['accept_header'],
        ],
    ],
]);

// 5a. No 3DS needed — poll for final state
if ($charge->getAction() === null) {
    $final = $sdk->awaitChargeState($payment->getId());
    echo $final->getState(); // 'SUCCEEDED'
}

// 5b. 3DS required — redirect the customer immediately. The bank's
//     authentication session times out quickly; do your order bookkeeping
//     on the return_url / webhook, never between these two lines.
if ($charge->getAction()?->getRedirectUrl() !== null) {
    header('Location: ' . $charge->getAction()->getRedirectUrl());
    exit;
}
```

> **`ip`, `user_agent` and `accept_header` must come from the customer's browser.** The browser
> calls `GET /cards/browser-data` (authenticated with `client_id:shareable_key`) immediately
> before the charge; the response carries all three, and your server merges them into
> `browser_data`. The endpoint reports whatever fetched it, so a call from your own server
> returns the server's address and headers — which the issuer rejects during 3-D Secure. That
> is why the SDK exposes no method for it: from PHP it would produce exactly the wrong values.
>
> Reading them off the incoming request instead is not the way round it. The Accept headers
> there do belong to the customer, but `ip` does not survive a proxy, and the contract wants
> all three from one observation rather than a mix of two sources.
>
> **`gw_url` — GoPay's hosted payment gateway.** The `PaymentDetails` object contains a `gw_url` field: the address of GoPay's hosted gateway for this payment, which offers the customer every payment method the eshop has enabled and drives the payment while the customer is on it. It is a destination the customer is sent to — it **cannot be embedded in your own checkout**, and that, not a missing payment method, is the reason not to redirect there by default. Driving the payment inside your own UI is what this SDK's flow (`createPayment()` → `chargePayment()`) is for; that checkout integration today covers card, Google Pay, Apple Pay and QR payments and is being extended to further methods, while the gateway already offers every method the eshop has enabled. Either way the payment stays fully v4-observable: `getPaymentStatus()` reports the final state once the customer completes it, exactly as it would for a payment charged directly through v4. Drive one or the other for a given payment attempt, never both.

---

## Configuration

```php
use GoPay\Payments\Config;
use GoPay\Payments\Environment;

$config = new Config(
    environment:         Environment::Production, // Environment::Sandbox (default)
    baseUrl:             null,          // override base URL (e.g. staging); null = use environment
    debugLoggingEnabled: false,         // log request/response to error_log (default false)
    onError:             null,          // callable(\Throwable): void — called before throwing
    shareableKey:        null,          // shareable key for browser SDK initialisation
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `environment` | `Environment` | `Sandbox` | API environment |
| `baseUrl` | `?string` | `null` | Override the resolved URL (e.g. for staging) |
| `debugLoggingEnabled` | `bool` | `false` | Log to `error_log` |
| `onError` | `?callable` | `null` | Invoked before every thrown exception |
| `shareableKey` | `?string` | `null` | Shareable key for `getBrowserKeys()` |

### Environments

| Environment | Base URL |
|---|---|
| `Environment::Sandbox` | `https://gw.sandbox.gopay.com/gp-gw/api/4.0` |
| `Environment::Production` | `https://gate.gopay.com/gp-gw/api/4.0` |

---

## PSR-18 HTTP client injection

By default, `php-http/discovery` auto-discovers an installed PSR-18 client. You can inject your own:

```php
use GoPay\Payments\GoPayClient;
use GoPay\Payments\Config;

$sdk = new GoPayClient(
    config:          new Config(),
    httpClient:      $myPsr18Client,        // Psr\Http\Client\ClientInterface
    requestFactory:  $myRequestFactory,     // Psr\Http\Message\RequestFactoryInterface
    streamFactory:   $myStreamFactory,      // Psr\Http\Message\StreamFactoryInterface
);
```

---

## API reference

### Authentication

```php
// Authenticate (client_credentials grant)
$sdk->authenticate(string $clientId, string $clientSecret, string $scope): void

// Check if a token is stored
$sdk->isAuthenticated(): bool

// Clear tokens
$sdk->logout(): void

// Store shareable key for browser SDK
$sdk->setShareableKey(string $key): void

// Return shareable_key + client_id for browser SDK init (safe to expose to the browser)
$sdk->getBrowserKeys(): array{shareable_key: string, client_id: string}
```

### Payments

```php
// Create a payment session
$sdk->createPayment(string $goid, array $params): PaymentDetails

// Get payment status
$sdk->getPaymentStatus(string $paymentId): PaymentDetails

// Charge a payment (card token / Google Pay / Apple Pay)
$sdk->chargePayment(string $paymentId, array $params): PaymentChargeResponse

// Get charge state (poll manually)
$sdk->getChargeState(string $paymentId): PaymentChargeStatusResponse

// Poll charge state until terminal (throws on FAILED / timeout)
// WARNING: blocks the PHP process — see "Production deployment" below.
$sdk->awaitChargeState(
    string $paymentId,
    int $timeoutSeconds = 30,
    int $pollIntervalMs = 1_000,
): PaymentChargeStatusResponse

// Google Pay configuration (pre-filled paymentDataRequest)
$sdk->getGooglePayInfo(string $paymentId): array

// Apple Pay configuration (applepayVersion, applePayPaymentRequest)
$sdk->getApplePayInfo(string $paymentId): array

// Apple Pay merchant validation (server-side; forward validationURL from browser)
$sdk->validateApplePayMerchant(string $paymentId, ?array $body = null, ?string $origin = null): array

// QR payment information (recipient details + base64 QR image)
$sdk->getQrPaymentInfo(string $paymentId, ?string $format = null): QRPaymentDetails
```

### Cards

```php
// Get stored card details
$sdk->getCardDetails(string $cardId): PermanentCardTokenDetails

// Delete a stored card
$sdk->deleteCard(string $cardId): void

// Tokenize JWE payload from the browser iframe (returns permanent token)
$sdk->tokenizeEncryptedCard(string $payload): PermanentCardTokenDetails
```

### Refunds

Server-side only — `refundPayment` needs the `payment:write` scope, which a payment-scoped
browser token never carries.

```php
// Refund a payment; pass the full amount for a full refund
$sdk->refundPayment(string $paymentId, array $params): RefundDetails

// List all refunds for a payment
$sdk->listRefunds(string $paymentId): list<RefundDetails>

// Get a single refund by its own ID
$sdk->getRefund(string $refundId): RefundDetails

// Poll a refund until it reaches SUCCESS or FAILED
$sdk->awaitRefundState(string $refundId, int $timeoutSeconds = 30, int $pollIntervalMs = 1000): RefundDetails
```

```php
$refund = $sdk->refundPayment($paymentId, ['amount' => 10000]);
echo $refund->getState();   // 'REQUESTED' — refunds are asynchronous

// Poll until it settles; awaitRefundState does the loop for you
$settled = $sdk->awaitRefundState($refund->getId());
echo $settled->getState();  // 'SUCCESS' | 'FAILED'
```

A `FAILED` refund is returned rather than raised — unlike `awaitChargeState()`, which raises on
failure. The refundable amount is untouched, so the caller decides whether to retry.

`amount` is in minor units and must be positive — the API rejects `0` and negative values
with `400`.

A card payment can only be refunded in full at first; a partial refund attempted too early is
rejected with `409`. This is not in the OpenAPI spec — it is the gateway's own rejection,
reproduced against the sandbox, which words it as:

> Partial refund is not allowed for this payment; only a full refund is possible
> (e.g. a card payment before settlement can only be fully reversed)

`RefundDetails` carries `id`, `state`, `amount`, `currency`, `created_at` and `updated_at`.
It has no `payment_id` and no failure reason — the gateway does not return them, so keep
your own mapping if you need to resolve a refund back to its payment.

---

### Payment links

A payment link lets you collect a payment without an integration: you create the link, share
its `url`, and the payment is created when the customer opens it. Server-side only — creating
and disabling a link need the `payment:write` scope, reading one needs `payment:read`.

```php
// Create a link; `payment` is required, `expires_in` (seconds) and `reusable` are optional
$sdk->createPaymentLink(string $goid, array $params): LinkDetails

// Read a link's current settings and state
$sdk->linkStatus(string $goid, string $linkId): LinkDetails

// Disable a link so it can no longer start a new payment
$sdk->disableLink(string $goid, string $linkId): void
```

```php
$link = $sdk->createPaymentLink($goid, [
    'expires_in' => 3600,   // omit for a link that never expires
    'reusable'   => false,  // one-shot; default is true
    'payment'    => [
        'amount'       => 15000,
        'currency'     => 'CZK',
        'order_number' => '2026-00042',
        'customer'     => ['email' => 'payer@example.com'],
        'callback'     => [
            'notification_url' => 'https://yourshop.example.com/gopay/notify',
            'return_url'       => 'https://yourshop.example.com/gopay/return',
        ],
    ],
]);

echo $link->getUrl();   // share this with the customer
echo $link->getId();    // use this on linkStatus() / disableLink()
```

`id` and `url` are two different identifiers and neither can be derived from the other: `id`
is the numeric link ID used by this API, while `url` ends in a random ten-character code that
is the only thing protecting the link.

A **reusable** link (the default) creates a new payment on every visit — and every one of them
carries the same `order_number` and the same `notification_url`, so your shop can receive
notifications for several payments that all look like one order. Check that it tolerates this
before sharing a reusable link.

A **one-shot** link (`reusable => false`) is consumed by the first visit and then keeps
redirecting to the payment it created, so a customer who refreshes or comes back later still
reaches their payment. It cannot be disabled afterwards — `disableLink()` answers `409`,
because the link is already inactive. To stop that payment, cancel the payment itself.

Expiry is evaluated on read: once a link is past its `expires_at`, `linkStatus()` reports
`active: false` with stop reason `EXPIRED` without anything being written first.

`LinkDetails` carries `id`, `url`, `active`, `reusable`, and — once the link is no longer
active — `stop_reason` (`FROM_API`, `USED` or `EXPIRED`). While the link is active,
`getStopReason()` returns `null`. A link that belongs to another eshop answers `404`, not
`403`, so the response does not reveal whether it exists.

---

### Recurrences

A recurrence holds a payment template and creates payments from it. In v4 it is a standalone
entity with its own ID — not something attached to an existing payment, the way v3's
`createRecurrence($paymentId, …)` was. Server-side only: every operation except reading needs
the `payment:write` scope, which a payment-scoped browser token never carries.

```php
// Create a recurrence; returned in state NEW, no payment exists yet
$sdk->createRecurrence(string $goid, array $params): RecurrenceDetails

// Read its current state and the payment it last created
$sdk->recurrenceStatus(string $recId): RecurrenceDetails

// Stop it permanently
$sdk->stopRecurrence(string $recId): void

// Create the first payment — the one the customer pays to activate the recurrence
$sdk->startRecurrence(string $recId, ?array $params = null): PaymentDetails

// Create a further payment, once the recurrence is STARTED
$sdk->recurrenceNext(string $recId, ?array $params = null): PaymentDetails
```

#### Two kinds

The create body is a discriminated union on `type`, and the variants differ by more than that
one field. An **`AUTO`** recurrence carries a `schedule` and GoPay creates the payments itself;
an **`ON_DEMAND`** one must not carry a schedule, because you ask for each further payment with
`recurrenceNext()`.

```php
// AUTO — GoPay creates a payment every month
$recurrence = $sdk->createRecurrence($goid, [
    'type'               => 'AUTO',
    'schedule'           => ['period' => 'MONTH', 'cycle' => 1],
    'recurrence_date_to' => '2027-09-04',
    'payment'            => [
        'amount'       => 1500,
        'currency'     => 'CZK',
        'order_number' => '2025010199',
        'customer'     => ['email' => 'john.doe@example.com'],
        'callback'     => [
            'notification_url' => 'https://yourshop.example.com/gopay/notify',
            'return_url'       => 'https://yourshop.example.com/gopay/return',
        ],
    ],
]);

// ON_DEMAND — no schedule; you call recurrenceNext() when you want the next payment
$recurrence = $sdk->createRecurrence($goid, [
    'type'               => 'ON_DEMAND',
    'recurrence_date_to' => '2027-09-04',
    'payment'            => [ /* same shape, no schedule anywhere */ ],
]);
```

`recurrence_date_to` is required for both and is the last day the recurrence may create a
payment, as a plain `yyyy-MM-dd` date — a value carrying a time is rejected with `400`. It
belongs at the top level, never inside `schedule`. Both variants are `additionalProperties:
false`, so an unknown field at **any** level, including inside `payment.customer`, is a `400`
rather than something the server quietly drops.

#### The lifecycle, and the state names that do not match it

```php
$recurrence = $sdk->createRecurrence($goid, [...]);   // state NEW

$first = $sdk->startRecurrence($recurrence->getId()); // state REQUESTED, not STARTED
header('Location: ' . $first->getGwUrl());            // the customer pays this one

// …once the customer has paid it, the recurrence becomes STARTED
$next = $sdk->recurrenceNext($recurrence->getId());   // 409 before that point
```

`startRecurrence()` creates the first payment and moves the recurrence to **`REQUESTED`**, not
`STARTED`. It becomes `STARTED` only once the customer has actually paid that first payment.
For the whole window in between, `recurrenceNext()` answers `409` — so code that starts a
recurrence and immediately asks for the next payment gets a conflict, not a payment. Calling
`startRecurrence()` again while the first payment is in flight also answers `409`.

#### Per-payment overrides

`startRecurrence()` and `recurrenceNext()` both take an optional body that overrides the stored
template for that one payment: `amount`, `order_number`, `order_description`,
`additional_params`, `customer` and `callback`.

```php
$payment = $sdk->recurrenceNext($recId, [
    'amount'       => 2500,                      // this instalment only
    'order_number' => '2026-00042',
    'customer'     => ['first_name' => 'Jane'],  // merged, not replaced
]);
```

`customer` is merged field by field rather than replaced, so a partial one is fine — it may
omit `email`, even though the shared `Customer` schema requires one. An unknown field
anywhere in the override is a `400`.

> **An empty nested object needs `(object) []`, not `[]`.** PHP cannot tell an empty map from
> an empty list, so `['customer' => []]` is encoded as `{"customer":[]}` and the gateway
> answers `400` — *Cannot deserialize value of type Customer from Array value*. The SDK
> coerces the **top level** of a request body to an object for you, so `startRecurrence($id, [])`
> is fine, but it deliberately stops there: coercing recursively would turn a legitimately
> empty list such as `additional_params` into `{}`. Where you genuinely want an empty object,
> say so:
>
> ```php
> $sdk->startRecurrence($recId, ['customer' => (object) []]);   // {"customer":{}}  → 201
> $sdk->startRecurrence($recId, ['customer' => []]);            // {"customer":[]}  → 400
> ```
>
> In practice an empty `customer` overrides nothing, so omitting the key entirely is usually
> what you want.

#### Stopping

`stopRecurrence()` answers `204` and moves the recurrence to `STOPPED` with `stop_reason`
`CANCELLED_VIA_API`. It cannot be undone: a second stop answers `409`, and so does starting a
stopped recurrence. Payments the recurrence already created are unaffected — cancel or refund
those individually.

`RecurrenceDetails` carries `id`, `type`, `state`, `recurrence_date_to`, `payment`, plus
`schedule` for an `AUTO` recurrence and `stop_reason` once it is `STOPPED` (`RECURRENCE_EXPIRED`,
`CARD_EXPIRED`, `CANCELLED_VIA_API`, `CANCELLED_VIA_BACKOFFICE`, `CANCELLED_BY_GOPAY` or
`UNKNOWN`). While it is still running, `getStopReason()` returns `null`.

`getPayment()` returns a `RecurrencePayment`, not a `PaymentDetails`. Before the recurrence is
started it is the stored template, so `id`, `state`, `gw_url` and `payment_secret` are all
`null` and only fill in once a payment exists.

A recurrence ID that is well-formed but does not exist answers `404`; one that is not a valid
ID at all answers `400`.

> **`RecurrenceState::_NEW`, with an underscore.** `new` is a reserved word in PHP, so the
> generated constant for the `NEW` state is `_NEW`. Only the PHP constant is affected — the
> value on the wire is the plain string `NEW`.

---

## Response objects

All API methods return typed objects. Use the provided getters to access fields:

```php
$payment = $sdk->createPayment($goid, [...]);
echo $payment->getId();     // unique payment ID
echo $payment->getState();  // 'CREATED', 'PAID', etc.
echo $payment->getAmount(); // amount in minor units (int)

$charge = $sdk->chargePayment($payment->getId(), [...]);
echo $charge->getState();                    // 'SUCCEEDED', 'AUTHENTICATION_PENDING', etc.
echo $charge->getAction()?->getRedirectUrl(); // 3DS URL (null if no redirect needed)

$card = $sdk->tokenizeEncryptedCard($jwePayload);
echo $card->getToken();     // permanent card token for future charges
echo $card->getMaskedPan(); // '411111******1111'
```

### Charge flow for 3DS cards

```php
$charge = $sdk->chargePayment($paymentId, $params);

if ($charge->getAction()?->getRedirectUrl() !== null) {
    // 3DS authentication required — redirect the customer, right now
    header('Location: ' . $charge->getAction()->getRedirectUrl());
    exit;
}

// No 3DS — charge is complete or in processing; poll for result
$final = $sdk->awaitChargeState($paymentId);
echo $final->getState(); // 'SUCCEEDED'
```

> **Redirect to the 3DS URL immediately.** The redirect URL opens an authentication session
> held open by the customer's bank, and that session has a **short timeout on the bank's side**.
> If the customer does not arrive in time, the bank cancels the authentication and the payment
> fails — there is nothing the SDK or GoPay can do about it, and retrying means starting a new
> payment.
>
> So `getRedirectUrl()` must be followed by the redirect and nothing else. Do not put work
> between the two:
>
> - no order bookkeeping, database writes, invoice generation or stock reservations
> - no confirmation emails or other outbound calls (an unreachable SMTP host alone can burn the
>   window)
> - no "please wait, redirecting…" interstitial, and no meta-refresh or JavaScript timer
> - no handing the URL to a queue, cron or background job to open later
> - no storing it to reuse on a later request — the URL is good for this one attempt, now
>
> All of that belongs **after** the customer comes back, in your `return_url` handler or — better,
> because it does not depend on the customer's browser returning at all — in the webhook delivered
> to `notification_url`. See [Polling payment state after a redirect](#polling-payment-state-after-a-redirect).

### Polling payment state after a redirect

After 3DS the customer is redirected to your `return_url`. At that point use
`getPaymentStatus()` and `PaymentPoller` to determine the outcome:

```php
use GoPay\Payments\PaymentPoller;

// On your return_url handler:
$paymentId = $_GET['payment_id'];

do {
    sleep(2);
    $payment = $sdk->getPaymentStatus($paymentId);
} while (PaymentPoller::isPending($payment->getState()));

if (PaymentPoller::isSuccessful($payment->getState())) {
    // PAID or AUTHORIZED
    echo 'Payment succeeded: ' . $payment->getState();
} else {
    // CANCELED or TIMEOUTED
    echo 'Payment did not complete: ' . $payment->getState();
}
```

`PaymentPoller` groups payment states into three buckets:

| Group | States | Meaning |
|---|---|---|
| Pending | `CREATED`, `PAYMENT_METHOD_CHOSEN` | Still in progress — keep polling |
| Successful | `PAID`, `AUTHORIZED` | Completed successfully |
| Failed | `CANCELED`, `TIMEOUTED` | Did not complete |

Post-success states (`REFUNDED`, `PARTIALLY_REFUNDED`) are terminal — `isTerminal()` returns `true` for them.

---

### QR payment flow

```php
// 1. Create the payment session (same as any other payment)
$payment = $sdk->createPayment($goid, [
    'amount'       => 1990,
    'currency'     => 'CZK',
    'order_number' => 'ORDER-001',
    'customer'     => ['email' => 'customer@example.com'],
    'callback'     => [
        'notification_url' => 'https://yourshop.com/notify',
        'return_url'       => 'https://yourshop.com/return',
    ],
]);

// 2. Retrieve QR code and recipient details
$qr = $sdk->getQrPaymentInfo($payment->getId());         // 'png' (default) or 'svg'
$imageBase64 = $qr->getQrCode();      // base64-encoded image

// 3. Render to the customer
echo '<img src="data:image/png;base64,' . $imageBase64 . '" alt="QR payment">';
echo 'Amount: ' . $qr->getAmount() . ' ' . $qr->getCurrency();

// 4. Poll until the customer pays (webhook-preferred; polling shown for completeness)
use GoPay\Payments\PaymentPoller;
do {
    sleep(3);
    $status = $sdk->getPaymentStatus($payment->getId());
} while (PaymentPoller::isPending($status->getState()));

echo PaymentPoller::isSuccessful($status->getState()) ? 'Paid' : 'Not paid';
```

---

## Error handling

All SDK methods throw on error:

| Exception | When |
|---|---|
| `GoPaySdkException` | Config errors, auth failures, timeout, argument errors |
| `GoPayHttpException` | Non-2xx API responses (status + body available) |

```php
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\ErrorCode;

try {
    $payment = $sdk->createPayment($goid, $params);
} catch (GoPayHttpException $e) {
    echo $e->status;  // e.g. 422
    var_dump($e->body); // decoded JSON or raw string
} catch (GoPaySdkException $e) {
    echo $e->errorCode->value; // e.g. 'AUTH_TOKEN_MISSING'
    echo $e->getMessage();
}
```

### Error codes (`ErrorCode` enum)

| Code | Meaning |
|---|---|
| `AuthTokenMissing` | No token; call `authenticate()` first |
| `AuthRefreshFailed` | Token refresh HTTP error |
| `AuthInvalidResponse` | Token response missing required fields |
| `AuthCredentialsMissing` | No stored client credentials |
| `AuthUnauthorized` | Still 401 after token refresh |
| `NetworkError` | Transport-level failure, including timeouts |
| `ChargeTimeout` | `awaitChargeState()` or `awaitRefundState()` timed out |
| `ChargeFailed` | Charge reached FAILED state |
| `UnexpectedResponse` | API responded with an unexpected body shape |
| `InvalidConfig` | Bad configuration |
| `InvalidArgument` | Empty required argument |

### `onError` callback

```php
$sdk = new GoPayClient(new Config(
    onError: function (\Throwable $e): void {
        // Fires before every throw — use for logging/monitoring
        $logger->error('GoPay error', ['exception' => $e]);
    },
));
```

---

## Production deployment

### Token caching

`GoPayClient` stores the OAuth2 access token in memory inside a single instance.
In conventional PHP-FPM / mod_php deployments **each HTTP request is a new process**,
so every `new GoPayClient(...)` + `authenticate()` call makes a fresh round-trip to
the GoPay token endpoint (typically 50–200 ms).

Tokens are valid for several minutes. To avoid re-fetching on every request, store
the raw token in a shared cache (APCu, Redis, Memcached) and restore it before
making API calls:

```php
use GoPay\Payments\GoPayClient;
use GoPay\Payments\Config;
use GoPay\Payments\Environment;

function getGoPayClient(): GoPayClient {
    $cacheKey = 'gopay_token_' . md5(CLIENT_ID . SCOPE);
    $sdk = new GoPayClient(new Config(environment: Environment::Production));

    $cached = apcu_fetch($cacheKey, $success);
    if ($success && is_array($cached)) {
        // pseudo-code — getHttp() is not yet public; see note below
        // $sdk->getHttp()->getTokenStore()->setToken($cached['token'], $cached['expires_in']);
        // $sdk->getHttp()->getTokenStore()->setClientCredentials(CLIENT_ID, CLIENT_SECRET, SCOPE);
    } else {
        $sdk->authenticate(CLIENT_ID, CLIENT_SECRET, SCOPE);
    }

    return $sdk;
}
```

> A first-class `TokenCacheInterface` (injectable into `Config`) is planned for a future
> release. Until then, the pattern above or a singleton per worker process is the
> recommended approach.

### `awaitChargeState` / `awaitRefundState` in web contexts

`awaitChargeState()` and `awaitRefundState()` both `usleep()` in a loop and **block the PHP
worker process** for up to `$timeoutSeconds` (default 30 s). Under concurrent load this can
exhaust the worker pool. Prefer the **webhook-driven pattern** for production web servers:

1. GoPay POSTs a notification to your `notification_url`.
2. Your handler calls `getChargeState()` — or `getRefund()` for a refund — once and records
   the result.
3. Return HTTP 200 immediately.

Use either poller only in CLI scripts or environments with ample worker headroom.

---

## Browser SDK compatibility

The PHP SDK handles the server side; the GoPay browser SDK handles the card form in an iframe.

1. **Browser**: `mountCardForm()` → user enters card → iframe submits → returns `{ token, card_id }`.
2. **Server**: `chargePayment($paymentId, ['payment_instrument' => ['payment_instrument' => 'PAYMENT_CARD', 'input' => ['input_type' => 'CARD_TOKEN', 'card_token' => $token]]])`.

For browser SDK initialisation, pass the result of `getBrowserKeys()` to the page:

```php
// Server-side (PHP)
$keys = $sdk->getBrowserKeys();
// $keys = ['shareable_key' => '...', 'client_id' => '...']
```

```html
<!-- Browser page -->
<script>
  GoPayBrowserSDK.init({
    clientId: '<?= htmlspecialchars($keys['client_id']) ?>',
    shareableKey: '<?= htmlspecialchars($keys['shareable_key']) ?>'
  });
</script>
```

### JWE tokenization flow (return-payload mode)

When the iframe is configured in `return-payload` mode, it returns a JWE compact serialization string instead of calling `/cards/tokens` itself. Forward it from the browser to your server, then exchange it for a permanent token:

```php
// Browser posts the JWE payload to your server
$jwePayload = $_POST['payload'];
$card = $sdk->tokenizeEncryptedCard($jwePayload);

// Now charge with the permanent token
$sdk->chargePayment($paymentId, [
    'payment_instrument' => [
        'payment_instrument' => 'PAYMENT_CARD',
        'input' => ['input_type' => 'CARD_TOKEN', 'card_token' => $card->getToken()],
        'browser_data' => [
            // EMV 3DS device data — collect in the browser, POST to your server.
            // ip, user_agent and accept_header come from GET /cards/browser-data,
            // fetched by the browser — never from $_SERVER. See the charge flow above.
            'language'           => $browserData['language'],
            'timezone'           => $browserData['timezone'],
            'screen_width'       => $browserData['screen_width'],
            'screen_height'      => $browserData['screen_height'],
            'color_depth'        => $browserData['color_depth'],
            'javascript_enabled' => $browserData['javascript_enabled'],
            'ip'                 => $browserData['ip'],
            'user_agent'         => $browserData['user_agent'],
            'accept_header'      => $browserData['accept_header'],
        ],
    ],
]);
```

---

## Examples

Runnable scripts in [`examples/`](examples/) demonstrate the full payment flow against the GoPay
sandbox. See [`examples/README.md`](examples/README.md) for setup and usage.

---

## Development

```bash
# Run all checks (code style + PHPStan + tests)
composer ci

# Individual steps
composer cs          # php-cs-fixer check
composer cs:fix      # auto-fix code style
composer phpstan     # PHPStan level 10
composer test        # PHPUnit
```

### Regenerating model classes

The PHP model classes in `src/Generated/` are auto-generated from the GoPay OpenAPI spec. To regenerate them:

```bash
# Requires Docker (no local Java/Node needed).
# SPEC_SOURCE is required — the OpenAPI spec to generate from, either a URL or a local path.
SPEC_SOURCE='<url-or-path>' composer codegen
```

This reads the spec from `SPEC_SOURCE` (a URL or a local path — see `scripts/codegen.sh`), strips the mock server entry the feed injects, runs the OpenAPI generator in Docker, and copies the output into `src/Generated/`. Review the diff before committing — in particular check that the namespace (`GoPay\Payments\Generated\Model`) and `ModelInterface` compatibility are preserved.

Do **not** edit files in `src/Generated/` by hand. If a model class is wrong, fix the upstream spec.

---

## License

MIT

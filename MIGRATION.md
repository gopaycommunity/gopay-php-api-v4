# Migration Guide: `gopay/payments-sdk` v1 (API v3) → v2 (API v4)

This SDK (v2) targets the GoPay Payments API **v4**. It has no source-level compatibility with
`gopay/payments-sdk` v1, which targeted API v3.

---

## Silently upgradeable — no consumer code change needed

| Change | Why it's invisible |
|---|---|
| `createPayment`: goid moves from body to URL path | Already auto-injected from config in v1 |
| `getStatus`: `/payments/payment/{id}` → `/payments/{id}` | Pure internal path change |
| `getQrPayment`: path + `/info` suffix | Pure internal path change |
| `getCardDetails` / `deleteCard`: `/payments/cards/` → `/cards/tokens/` | Pure internal path change |
| OAuth2 token endpoint | Same `/oauth2/token` path, same grant type |

---

## Hard breaking changes

### 1. Gateway URL must change (consumer-facing)

```php
// v1 (API v3)
new Config(['gatewayUrl' => 'https://gw.sandbox.gopay.com/api']);

// v2 (API v4)
new GoPayClient(new Config(environment: Environment::Sandbox));
// baseUrl: 'https://gw.sandbox.gopay.com/gp-gw/api/4.0'
```

Every consumer must update their initialization config.

### 2. Payment flow changed: create → charge (no redirect)

```php
// v1 (API v3) — redirect-based
$payment = $gopay->createPayment([...]);
header('Location: ' . $payment->json['gw_url']); // REDIRECT the customer

// v2 (API v4) — charge-based (server-side)
$payment = $sdk->createPayment($goid, [...]);
// gw_url is not used by the flow below. It is still GoPay's hosted gateway,
// offering every method the eshop has enabled — redirect there deliberately
// when you want the hosted integration rather than driving the payment in your
// own checkout. Either way getPaymentStatus() reports the final state once the
// customer completes it.
// Instead, obtain a card token from the browser iframe, then:
$charge = $sdk->chargePayment($payment->getId(), [
    'payment_instrument' => [
        'payment_instrument' => 'PAYMENT_CARD',
        'input' => [
            'input_type' => 'CARD_TOKEN',
            'card_token' => $cardToken, // from the browser SDK iframe
        ],
        // v4 requires EMV 3DS browser data on a card charge — see README quick start
        'browser_data' => [
            'language'           => $browserData['language'],
            'timezone'           => $browserData['timezone'],
            'screen_width'       => $browserData['screen_width'],
            'screen_height'      => $browserData['screen_height'],
            'color_depth'        => $browserData['color_depth'],
            'user_agent'         => $_SERVER['HTTP_USER_AGENT'],
            'accept_header'      => AcceptHeader::fromServerGlobals(),
            'javascript_enabled' => $browserData['javascript_enabled'],
        ],
    ],
]);
if ($charge->getAction()?->getRedirectUrl()) {
    header('Location: ' . $charge->getAction()->getRedirectUrl()); // 3DS only
}
```

### 3. `urlToEmbedJs()` is removed

The embed.js concept is gone in v4. Use the browser SDK iframe instead.

### 4. Refunds are a separate resource

`refundPayment()` still exists but neither its signature nor its return type survives, and
`getHistoryRefunds()` is gone entirely. Both need consumer changes.

```php
// v1 (API v3) — refund described by a nested EET-era payload, returns the payment
$gopay->refundPayment($paymentId, ['amount' => 1000]);
$gopay->getHistoryRefunds($paymentId);

// v2 (API v4) — refund is its own resource with its own ID
$refund = $sdk->refundPayment($paymentId, ['amount' => 1000]); // RefundDetails
$refund->getId();                    // refund ID, not the payment ID
$refund->getState();                 // REQUESTED → SUCCESS | FAILED (asynchronous)

$sdk->listRefunds($paymentId);       // list<RefundDetails>
$sdk->getRefund($refund->getId());   // read one refund back
```

The `201` response only means the refund was accepted — poll `getRefund()` until the state
leaves `REQUESTED`. `RefundDetails` carries no `payment_id`, so keep your own mapping if you
need to resolve a refund back to its payment.

### 5. Recurrences are standalone entities

`createRecurrence()` still exists but nothing about it survives: in v3 a recurrence was created
on top of an existing payment, in v4 it is its own resource with its own ID, created against
the eshop and holding a payment template of its own.

```php
// v1 (API v3) — recurrence attached to a payment that already exists
$gopay->createRecurrence($paymentId, [...]);

// v2 (API v4) — recurrence created against the eshop, no payment yet
$recurrence = $sdk->createRecurrence($goid, [
    'type'               => 'ON_DEMAND',    // or 'AUTO' with a 'schedule'
    'recurrence_date_to' => '2027-09-04',
    'payment'            => [ /* the template every payment is created from */ ],
]);

$first = $sdk->startRecurrence($recurrence->getId());  // creates the first payment
$next  = $sdk->recurrenceNext($recurrence->getId());   // each further payment
$sdk->stopRecurrence($recurrence->getId());
```

Creating a recurrence no longer charges anything: it stores a template and nothing else. The
first payment is created by `startRecurrence()`, and the recurrence only becomes `STARTED` once
the customer has paid it — `recurrenceNext()` answers `409` until then. See
[Recurrences](README.md#recurrences) in the README for the full state machine.

---

## Methods removed — no v4 equivalent

These 9 methods are gone in v4. They will throw HTTP 404 if called against the v4 API.

| v1 method | v3 endpoint | v4 status |
|---|---|---|
| `refundPaymentEET` | `POST /payments/payment/{id}/refund` | ✗ EET abolished Jan 2023, no v4 equivalent |
| `captureAuthorization` / `captureAuthorizationPartial` | `POST /payments/payment/{id}/capture` | ✗ |
| `voidAuthorization` | `POST /payments/payment/{id}/void-authorization` | ✗ |
| `getPaymentInstruments` / `getPaymentInstrumentsAll` | `GET /eshops/eshop/{goid}/payment-instruments/...` | ✗ |
| `getAccountStatement` | `POST /accounts/account-statement` | ✗ |
| `getEETReceiptByPaymentId` / `findEETReceiptsByFilter` | `GET/POST /eet-receipts` | ✗ (EET abolished Jan 2023) |

Consumers using only `createPayment` + `getStatus` + basic card management have minimal migration
work (update config URL + add a `chargePayment()` call). Consumers using the listed methods need
to remove those calls — there is no v4 equivalent.

---

## New in v4

| Method | Description |
|---|---|
| `chargePayment($id, $params)` | Charge a payment with a card token, Google Pay, or Apple Pay |
| `getChargeState($id)` | Poll the outcome of a charge (REQUESTED → PROCESSING → SUCCEEDED/FAILED) |
| `awaitChargeState($id)` | Synchronous poll loop until terminal outcome |
| `validateApplePayMerchant($id, ...)` | Server-side Apple Pay merchant validation |
| `getGooglePayInfo($id)` | Pre-filled Google Pay `paymentDataRequest` |
| `getApplePayInfo($id)` | Apple Pay session configuration |
| `getBrowserKeys()` | Return shareable_key + client_id for browser SDK initialisation |
| `tokenizeEncryptedCard($jwePayload)` | Server-side card tokenization from iframe JWE |
| `listRefunds($id)` | List every refund issued against a payment |
| `getRefund($refundId)` | Read a single refund by its own v4 refund ID |
| `awaitRefundState($refundId)` | Synchronous poll loop until a refund settles (SUCCESS/FAILED) |
| `recurrenceStatus($recId)` | Read a recurrence and the payment it last created |
| `startRecurrence($recId, ...)` | Create the first payment of a recurrence |
| `recurrenceNext($recId, ...)` | Create a further payment from a started recurrence |
| `stopRecurrence($recId)` | Stop a recurrence permanently |

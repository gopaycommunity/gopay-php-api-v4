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
// gw_url is not used by the flow below — only redirect to it deliberately,
// for a payment method not yet supported by v4's charge API. Doing so hands
// off control to the hosted (v3-backed) flow, but getPaymentStatus() still
// reports the final state once the customer completes it.
// Instead, obtain a card token from the browser iframe, then:
$charge = $sdk->chargePayment($payment->getId(), [
    'payment_instrument' => [
        'payment_instrument' => 'PAYMENT_CARD',
        'input' => [
            'input_type' => 'CARD_TOKEN',
            'card_token' => $cardToken, // from the browser SDK iframe
        ],
    ],
]);
if ($charge->getAction()?->getRedirectUrl()) {
    header('Location: ' . $charge->getAction()->getRedirectUrl()); // 3DS only
}
```

### 3. `urlToEmbedJs()` is removed

The embed.js concept is gone in v4. Use the browser SDK iframe instead.

---

## Methods removed — no v4 equivalent

These 12 methods are gone in v4. They will throw HTTP 404 if called against the v4 API.

| v1 method | v3 endpoint | v4 status |
|---|---|---|
| `refundPayment` | `POST /payments/payment/{id}/refund` | ✗ refunds were removed from the v4 API schema (unfinished endpoint), no v4 equivalent |
| `getHistoryRefunds` | `GET /payments/payment/{id}/refunds` | ✗ refunds were removed from the v4 API schema (unfinished endpoint), no v4 equivalent |
| `createRecurrence` | (v3 recurrence endpoint) | ✗ recurrences were removed from the v4 API schema (unfinished endpoint), no v4 equivalent |
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

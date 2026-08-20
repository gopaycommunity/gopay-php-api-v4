# gopay-php-api-v4 — CLAUDE.md

## Project

GoPay Payments API v4 — PHP server-side SDK. Mirrors the JavaScript reference SDK at
`../gp-gw-js-sdk` (sdk/ = server). Browser SDK compatibility means the PHP server creates the
payment and charges a card token produced by the GoPay-hosted iframe.

## Model classes — generated

The model DTOs in `src/Generated/Model/` are **auto-generated** from the GoPay OpenAPI spec
(`https://api-docs.gopay.com/spec/en/payments.yaml`). They implement
`GoPay\Payments\Generated\Model\ModelInterface`. `HttpClient::deserialize()` calls
`ObjectSerializer::deserialize($data, $type)` directly.

Run `composer codegen` to regenerate (requires Docker — image
`openapitools/openapi-generator-cli:v7.9.0`). Config lives in `openapitools.json`. The script
fetches the latest spec, generates into `.codegen-tmp/`, and copies `lib/Model/*`,
`ObjectSerializer.php`, and `HeaderSelector.php` into `src/Generated/`. Review the diff before
committing — in particular check that the namespace (`GoPay\Payments\Generated\Model`) and
`ModelInterface` compatibility are preserved.

Do **not** edit files in `src/Generated/` by hand. If a model class is wrong, fix the upstream spec.

The entire `src/Generated/` directory is **excluded from PHPStan** analysis (see `phpstan.neon`).
PHPStan still reads the class definitions for type inference in analysed files, it just doesn't
check the Generated files themselves for level-10 violations.

## Quality checks

```bash
composer ci
```

This runs in order: `cs` (php-cs-fixer), `phpstan`, `test` (phpunit). Run before every commit.
The pre-commit hook (captainhook) runs `composer ci` automatically.

Individual steps:
```bash
composer cs          # check code style
composer cs:fix      # auto-fix code style
composer phpstan     # static analysis (level 10, PHP 8.1)
composer test        # run unit tests
```

## Architecture

```
src/
├── GoPayClient.php          ← flat public API (one class, all methods)
├── Config.php               ← readonly VO: environment, baseUrl, debugLoggingEnabled, onError, shareableKey
├── Environment.php          ← backed enum: Sandbox / Production
├── Exception/
│   ├── ErrorCode.php        ← backed enum of machine-readable error codes
│   ├── GoPaySdkException.php ← lifecycle / config errors
│   └── GoPayHttpException.php ← non-2xx API responses
├── Http/
│   ├── HttpClient.php       ← PSR-18 wrapper, auth, error mapping, DTO deserialization
│   ├── AuthHandler.php      ← token injection, proactive refresh, 401 retry
│   ├── TokenStore.php       ← in-memory token + credential store
│   └── RequestOptions.php   ← per-request overrides
├── Module/
│   ├── AuthApi.php          ← authenticate / isAuthenticated / logout / setShareableKey / getBrowserKeys
│   ├── PaymentsApi.php      ← createPayment / chargePayment / getPaymentStatus / …
│   ├── CardsApi.php         ← getCardDetails / deleteCard / tokenizeEncryptedCard
│   └── RefundsApi.php       ← refundPayment / listRefunds / getRefund / awaitRefundState
└── Generated/               ← generated from OpenAPI spec (PHPStan-excluded from analysis)
    ├── ObjectSerializer.php
    ├── HeaderSelector.php
    └── Model/               ← PaymentDetails, PaymentChargeResponse, ChargeAction, …
        └── ModelInterface.php
```

## `gw_url` — escape hatch, not a redirect target for this SDK's own flow

`createPayment()` returns a `gw_url` field. **Do not redirect to it as part of this SDK's
own flow** (`createPayment()` → `chargePayment()`), which fully covers card payments.

`gw_url` is a deliberate escape hatch into the previous (v3) hosted-gateway processing —
reach for it when a payment needs a method or feature not yet implemented in the v4 charge
flow. Redirecting there hands off real-time control of the payment to the hosted flow for as
long as the customer is on it, but the payment stays fully v4-observable throughout: once the
customer completes it, `getPaymentStatus($paymentId)` reports the final state exactly as it
would for a payment charged directly through v4. Don't present `gw_url` as unsafe or purely
legacy in generated examples — it's the correct tool for methods v4 doesn't cover yet, not
dead code to avoid. The same nuance applies to the JavaScript SDK.

## Browser SDK coordination

The browser SDK (`gp-gw-js-sdk/browser-sdk`) tokenizes cards via the GoPay-hosted iframe. The
PHP SDK charges the resulting token. Browser-compat seam:

1. Browser: `mountCardForm()` → user enters card → iframe calls `POST /cards/tokens` → returns `{token, card_id}`.
2. PHP: `chargePayment($id, ['payment_instrument' => ['payment_instrument' => 'PAYMENT_CARD', 'input' => ['input_type' => 'CARD_TOKEN', 'card_token' => $token]]])`.

When the browser SDK's iframe yields a JWE payload (return-payload mode), the PHP server calls
`tokenizeEncryptedCard($jwePayload)` instead of the browser calling `POST /cards/tokens` directly.

## v3 → v4 migration

See `MIGRATION.md`. The SDK is v4-only — no source-level compat with `gopay/payments-sdk` v1.
The major changes: gateway URL and a create→charge flow replacing the default redirect flow;
`gw_url` remains available as an escape hatch for unsupported payment methods.
10 v3 methods (recurrences, pre-auth, EET, account statement, payment instruments) have no v4
equivalent. Recurrences and payment links were removed from the v4 API schema as unfinished
endpoints and their SDK modules dropped entirely; refunds were removed with them but came back
once the schema declared them again, on new paths with the refund as its own resource.

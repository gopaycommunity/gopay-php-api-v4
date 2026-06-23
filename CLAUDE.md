# gopay-php-api-v4 — CLAUDE.md

## Project

GoPay Payments API v4 — PHP server-side SDK. Mirrors the JavaScript reference SDK at
`../gp-gw-js-sdk` (sdk/ = server). Browser SDK compatibility means the PHP server creates the
payment and charges a card token produced by the GoPay-hosted iframe.

## Model classes — hand-written (not generated)

The model DTOs in `src/Generated/Model/` are currently **hand-written**, not generated. They
implement `GoPay\Payments\Generated\ModelInterface` which provides `fromArray(array $data): static`
for JSON hydration. `HttpClient::deserialize()` calls `$type::fromArray($data)` directly.

**Toolchain status**: `@openapitools/openapi-generator-cli@2.39.0` + Docker mode is confirmed
working (no Java needed — Docker image `openapitools/openapi-generator-cli:v7.9.0` must be
available). Config lives in `openapitools.json`.

**Blocker before enabling codegen**: The generator produces models that implement a different
`ModelInterface` (no `fromArray()`) and use `ObjectSerializer` for deserialization. Merging the
generated output requires either:
- Adding `fromArray()` to each generated model via a custom Mustache template, OR
- Updating `HttpClient::deserialize()` to use `ObjectSerializer::deserialize()` instead.

Run `composer codegen` to generate into `.codegen-tmp/` and inspect the diff before committing.
The script copies `lib/Model/*`, `ObjectSerializer.php`, and `HeaderSelector.php` into
`src/Generated/`. Review: namespace must be `GoPay\Payments\Generated\Model`, and `ModelInterface`
compatibility must be preserved.

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

## Running via Docker (no local PHP required)

```bash
docker compose run --rm php composer ci
docker compose run --rm php composer test
```

## Architecture

```
src/
├── GoPayClient.php          ← flat public API (one class, all methods)
├── Config.php               ← readonly VO: environment, baseUrl, timeout, onError, shareableKey
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
│   ├── AuthApi.php          ← authenticate / isAuthenticated / logout / getBrowserKeys
│   ├── PaymentsApi.php      ← createPayment / chargePayment / getPaymentStatus / …
│   ├── CardsApi.php         ← getCardDetails / deleteCard / tokenizeEncryptedCard
│   ├── RecurrencesApi.php   ← createRecurrence / startRecurrence / recurrenceNext / …
│   ├── RefundsApi.php       ← refundPayment / listRefunds / getRefund
│   └── LinksApi.php         ← createPaymentLink / linkStatus / disableLink
└── Generated/               ← hand-written model DTOs (PHPStan-excluded from analysis)
    ├── ModelInterface.php   ← fromArray(array): static contract
    └── Model/               ← PaymentDetails, PaymentChargeResponse, ChargeAction, …
```

## `gw_url` — do not use

`createPayment()` returns a `gw_url` field. **Do not use, suggest, or add it to examples.**
It exists solely for backward-compat with old redirect-based flows (pre-SDK). This SDK's flow is
always: `createPayment()` → `chargePayment()`. The same rule applies to the JavaScript SDK.

## Browser SDK coordination

The browser SDK (`gp-gw-js-sdk/browser-sdk`) tokenizes cards via the GoPay-hosted iframe. The
PHP SDK charges the resulting token. Browser-compat seam:

1. Browser: `mountCardForm()` → user enters card → iframe calls `POST /cards/tokens` → returns `{token, card_id}`.
2. PHP: `chargePayment($id, ['payment_instrument' => ['payment_instrument' => 'PAYMENT_CARD', 'input' => ['input_type' => 'CARD_TOKEN', 'card_token' => $token]]])`.

When the browser SDK's iframe yields a JWE payload (return-payload mode), the PHP server calls
`tokenizeEncryptedCard($jwePayload)` instead of the browser calling `POST /cards/tokens` directly.

## v3 → v4 migration

See `MIGRATION.md`. The SDK is v4-only — no source-level compat with `gopay/payments-sdk` v1.
The major changes: gateway URL, create→charge flow replacing redirects, recurrences redesign.
11 v3 methods (pre-auth, EET, account statement, payment instruments) have no v4 equivalent.

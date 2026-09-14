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

Run `SPEC_SOURCE=<url-or-path> composer codegen` to regenerate (requires Docker — image
`openapitools/openapi-generator-cli:v7.9.0`). `SPEC_SOURCE` is required and the script exits
without it; see *The "next spec", and the two ways to get it* below for what to pass. Config
lives in `openapitools.json`. The script vendors the spec into `spec/payments.yaml`, generates
into `.codegen-tmp/`, and copies `lib/Model/*`,
`ObjectSerializer.php`, and `HeaderSelector.php` into `src/Generated/`. Review the diff before
committing — in particular check that the namespace (`GoPay\Payments\Generated\Model`) and
`ModelInterface` compatibility are preserved.

Do **not** edit files in `src/Generated/` by hand. If a model class is wrong, fix the upstream spec.

### The "next spec", and the two ways to get it

The URL above (`api-docs.gopay.com`) is the **published** reference and the URL stamped into
every generated file's header. It is not where `composer codegen` fetches from. Models are
generated from the **next spec** — the upcoming version of the API spec, ahead of what the
published docs show — which comes from one of two places:

1. **GoPay's internal pre-release feed.** GoPay serves the next spec from an internal
   environment: `https://payments-api.beta.gopay.com/spec/en/payments.yaml`. Reachable only
   from inside GoPay (the host resolves to a private address behind an internal load
   balancer), and shut down overnight and at weekends, so a 404 or timeout out of hours is
   expected rather than an outage.
2. **Sideload from the API spec repo.** Check the spec repo out at the commit you want and
   point codegen at the local file — use this when the internal environment is unavailable,
   or when you need models generated against one specific spec commit.

`scripts/codegen.sh` takes the source as `SPEC_SOURCE`, a URL or a local path:

```bash
SPEC_SOURCE=https://payments-api.beta.gopay.com/spec/en/payments.yaml composer codegen
SPEC_SOURCE=../gopay-payments-api-v4/spec/payments.yaml composer codegen
```

Codegen writes `spec/payments.yaml` from `SPEC_SOURCE` and generates from that file, stripping
the Prism mock server the feed injects as `servers[0]` (it fails rather than generate if a mock
reference survives). The mock server itself is fine and stays where it runs — it backs "Try It"
in the Elements docs UI — it just must never reach a vendored spec file.

**Never pass `spec/payments.yaml` itself as `SPEC_SOURCE`.** The vendored copy lags the feed,
so regenerating from it silently rolls the models back. It is an output, not a source.

### This file is the only place the internal hostname belongs

The word "beta" in that hostname names the environment the spec is authored on. It says
nothing about the maturity of Payments API v4 or the stability of this SDK, both of which are
GA — but nobody outside GoPay can tell that apart, and nobody outside GoPay can reach the host
to find out, so to an integrator it reads as a product-status warning on the thing they are
about to integrate.

This repo is mirrored to public `gopaycommunity/gopay-php-api-v4` and `composer require` ships
the entire working tree, so keep the hostname in this file and nowhere else — not in
`README.md`, `MIGRATION.md`, `src/`, `scripts/` (hence `SPEC_SOURCE`), and above all not in
**commit subjects**: semantic-release copies every `fix:`/`feat:` subject verbatim into the
public `CHANGELOG.md`.

### Commit convention for spec updates

When a change comes from a new spec, the subject is:

```
fix: update api from next spec GPOMA-xxxx
```

Say that and nothing about where the bytes came from — not the hostname, not which of the two
sources was used, not "beta".

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
│   ├── RefundsApi.php       ← refundPayment / listRefunds / getRefund / awaitRefundState
│   ├── LinksApi.php         ← createPaymentLink / linkStatus / disableLink
│   └── RecurrencesApi.php   ← createRecurrence / recurrenceStatus / stopRecurrence / startRecurrence / recurrenceNext
└── Generated/               ← generated from OpenAPI spec (PHPStan-excluded from analysis)
    ├── ObjectSerializer.php
    ├── HeaderSelector.php
    └── Model/               ← PaymentDetails, PaymentChargeResponse, ChargeAction, …
        └── ModelInterface.php
```

## `gw_url` — GoPay's hosted gateway, not a redirect target for this SDK's own flow

`createPayment()` returns a `gw_url` field. **Do not redirect to it as part of this SDK's
own flow** (`createPayment()` → `chargePayment()`).

`gw_url` is the address of GoPay's **full hosted payment gateway**, offering the customer
every payment method the eshop has enabled, with GoPay driving the payment while they are on
it. It is **not** an escape hatch into v3 and **not** a fallback for methods v4 is missing —
that framing is wrong and has been corrected in the spec repo; do not reintroduce it here.
The reason not to send the customer there by default is that the gateway **cannot be embedded
in the merchant's own checkout**: it is a destination the customer is sent to. Driving the
payment inside the merchant's own UI is what the checkout integration is for, and that today
covers card, Google Pay, Apple Pay and QR payments and is being extended, while the gateway
already has everything.

Either way the payment stays fully v4-observable: once the customer completes it,
`getPaymentStatus($paymentId)` reports the final state exactly as it would for a payment
charged directly through v4. Don't present `gw_url` as unsafe or legacy in generated
examples — it is a supported integration in its own right, not dead code to avoid. The only
rule is to drive one or the other for a given payment attempt, never both. The same nuance
applies to the JavaScript SDK.

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
`gw_url` remains available as the hosted-gateway integration.
9 v3 methods (pre-auth, EET, account statement, payment instruments) have no v4 equivalent.
Refunds, payment links and recurrences were all removed from the v4 API schema as unfinished
endpoints and their SDK modules dropped, then came back one by one as the schema declared them
again — each on new paths and as its own resource. A recurrence is now standalone, created
against the eshop rather than on top of an existing payment.

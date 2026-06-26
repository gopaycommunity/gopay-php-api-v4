# Examples

Runnable scripts that demonstrate the core SDK flows against the GoPay sandbox.

## Setup

```bash
cp examples/.env.example examples/.env
```

Open `examples/.env` and fill in your sandbox credentials from [portal.gopay.cz](https://portal.gopay.cz):

```
GOPAY_PAYMENTS_V4_CLIENT_ID=...
GOPAY_PAYMENTS_V4_CLIENT_SECRET=...
GOPAY_PAYMENTS_V4_GOID=...
GOPAY_PAYMENTS_V4_SHAREABLE_KEY=...   # optional — only needed for browser-sdk-integration.php
```

## Run

```bash
composer install   # first time only

php examples/create-and-charge.php
php examples/browser-sdk-integration.php
```

## Scripts

### `create-and-charge.php`

Full payment lifecycle:

1. `createPayment()` — opens a payment session
2. `chargePayment()` — submits a card token (replace the placeholder with a real token from the browser SDK)
3. `awaitChargeState()` — polls until a terminal state (or 3DS redirect URL is printed)
4. `getPaymentStatus()` — reads the final payment record

### `browser-sdk-integration.php`

Server-side half of the browser iframe integration:

- Calls `getBrowserKeys()` and prints the `client_id` / `shareable_key` to embed in your HTML page.
- Shows both iframe modes: standard token flow and JWE return-payload tokenization.

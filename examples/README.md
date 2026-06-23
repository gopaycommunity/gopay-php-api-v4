# Examples

Runnable scripts that demonstrate the core SDK flows against the Stoplight mock server (no real
card is charged).

## Setup

```bash
cp examples/.env.example examples/.env
```

Open `examples/.env` and fill in your credentials from [portal.gopay.cz](https://portal.gopay.cz):

```
GOPAY_PAYMENTS_V4_CLIENT_ID=...
GOPAY_PAYMENTS_V4_CLIENT_SECRET=...
GOPAY_PAYMENTS_V4_GOID=...
GOPAY_PAYMENTS_V4_SHAREABLE_KEY=...   # optional — only needed for browser-sdk-integration.php
```

`GOPAY_PAYMENTS_V4_BASE_URL` is pre-filled with the Stoplight mock server — safe to leave as-is for local
testing. Change it to the sandbox URL when you want to hit a real environment.

## Run

```bash
composer install   # first time only

php examples/create-and-charge.php
php examples/browser-sdk-integration.php
```

Via Docker (no local PHP needed):

```bash
docker compose run --rm php php examples/create-and-charge.php
docker compose run --rm php php examples/browser-sdk-integration.php
```

## Scripts

### `create-and-charge.php`

Full payment lifecycle:

1. `createPayment()` — opens a payment session
2. `chargePayment()` — submits a mock card token
3. `awaitChargeState()` — polls until a terminal state (or 3DS redirect URL is printed)
4. `getPaymentStatus()` — reads the final payment record

### `browser-sdk-integration.php`

Server-side half of the browser iframe integration:

- Calls `getBrowserKeys()` and prints the `client_id` / `shareable_key` to embed in your HTML page.
- Shows both iframe modes: standard token flow and JWE return-payload tokenization.

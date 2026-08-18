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
php examples/refund-payment.php <payment_id> [amount]
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

### `refund-payment.php`

Refunds a payment and follows the refund to a terminal state:

1. `getPaymentStatus()` — refuses to continue unless the payment is `PAID` or `PARTIALLY_REFUNDED`
2. `listRefunds()` — sums the settled refunds to work out how much is still refundable
3. `refundPayment()` — refunds the remainder by default, or the amount given as the second argument
4. `getRefund()` — polls until the refund leaves `REQUESTED` (exit code `2` if it never does)
5. `listRefunds()` — prints every refund on the payment, then the payment's new state

Needs the `payment:write` scope. Two rejections worth knowing: `400` when the amount is not
positive, and `409` when the payment is not refundable — including a partial refund attempted
before the card transaction has been processed by the acquirer.

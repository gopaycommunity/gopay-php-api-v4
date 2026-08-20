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
php examples/refund-payment.php <payment_id> [amount_in_minor_units]
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
4. `awaitRefundState()` — polls until the refund settles as `SUCCESS` or `FAILED` (exit code `2` if it is still `REQUESTED` when the 30 s timeout is reached)
5. `listRefunds()` — prints every refund on the payment, then the payment's new state

Amounts are in **minor units** (e.g. `10000` = 100.00 CZK). With no amount the script refunds
whatever is still refundable, and it rejects a malformed, zero, negative or too-large amount
before calling the API.

Exit codes: `0` the refund reached `SUCCESS`, `1` it was rejected, reached `FAILED`, or the SDK
raised anything other than a poll timeout, `2` it was accepted but still `REQUESTED` when polling
gave up.

Needs the `payment:write` scope. Two rejections worth knowing: `400` when the amount is not
positive, and `409` when the payment is not refundable — including a partial refund attempted
before the card transaction has been processed by the acquirer.

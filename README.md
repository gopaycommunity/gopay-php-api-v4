# GoPay PHP SDK — Payments API v4

Server-side PHP SDK for [GoPay Payments API v4](https://api-docs.gopay.com/).

Requires **PHP ≥ 8.1**. Transport-agnostic — works with any PSR-18 HTTP client.

---

## Installation

```bash
composer require gopay/payments-sdk
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
    ],
]);

// 5a. No 3DS needed — poll for final state
if ($charge->getAction() === null) {
    $final = $sdk->awaitChargeState($payment->getId());
    echo $final->getState(); // 'SUCCEEDED'
}

// 5b. 3DS required — redirect the customer
if ($charge->getAction()?->getRedirectUrl() !== null) {
    header('Location: ' . $charge->getAction()->getRedirectUrl());
    exit;
}
```

> **`gw_url` — do not use.** The `PaymentDetails` object contains a `gw_url` field. Do **not** redirect the customer to it. It exists only for backward-compat with old redirect-based flows. This SDK's flow is always: `createPayment()` → `chargePayment()`.

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
| `Environment::Sandbox` | `https://api.sandbox.gopay.com/api/merchant/payments/4.0` |
| `Environment::Production` | `https://api.gopay.com/api/merchant/payments/4.0` |

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

### Recurrences

```php
// Create a recurring payment agreement
$sdk->createRecurrence(string $goid, array $params): RecurrenceDetails

// Get recurrence status
$sdk->recurrenceStatus(string $recId): RecurrenceDetails

// Stop a recurrence permanently
$sdk->stopRecurrence(string $recId): void

// Start a recurrence (triggers first charge)
$sdk->startRecurrence(string $recId, ?array $params = null): PaymentDetails

// Create the next instalment
$sdk->recurrenceNext(string $recId, ?array $params = null): PaymentDetails
```

### Refunds

```php
// Refund a payment (full or partial)
$sdk->refundPayment(string $paymentId, array $params): RefundDetails

// List all refunds for a payment
$sdk->listRefunds(string $paymentId): list<RefundDetails>

// Get a single refund
$sdk->getRefund(string $refundId): RefundDetails
```

### Payment links

```php
// Create a shareable payment link
$sdk->createPaymentLink(string $goid, array $params): LinkDetails

// Get link status
$sdk->linkStatus(string $linkId): LinkDetails

// Disable a link
$sdk->disableLink(string $linkId): void
```

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
    // 3DS authentication required — redirect the customer
    header('Location: ' . $charge->getAction()->getRedirectUrl());
    exit;
}

// No 3DS — charge is complete or in processing; poll for result
$final = $sdk->awaitChargeState($paymentId);
echo $final->getState(); // 'SUCCEEDED'
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
    echo $e->errorCode->value; // e.g. 'auth_token_missing'
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
| `NetworkTimeout` | Request timed out |
| `NetworkError` | Transport-level error |
| `ChargeTimeout` | `awaitChargeState()` timed out |
| `ChargeFailed` | Charge reached FAILED or unknown state |
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
    ],
]);
```

---

## v3 → v4 migration

See [MIGRATION.md](MIGRATION.md) for a full breakdown. The SDK is v4-only — not source-level compatible with `gopay/payments-sdk` v1.

Key changes:
- **Gateway URL changed** — update your `Config` initialization
- **No redirect-based flow** — replace `header('Location: gw_url')` with `chargePayment()`
- **Recurrences redesigned** — now standalone entities, not attached to a payment
- **11 v3 methods removed** — pre-auth capture/void, EET, account statement, payment instruments

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

# Via Docker (no local PHP required)
docker compose run --rm php composer ci
```

### Regenerating model classes

The PHP model classes in `src/Generated/` are auto-generated from the GoPay OpenAPI spec. To regenerate them:

```bash
# Requires Docker (no local Java/Node needed) and an internet connection
composer codegen
```

This fetches the latest spec from `https://api-docs.gopay.com/spec/en/payments.yaml`, runs the OpenAPI generator in Docker, and copies the output into `src/Generated/`. Review the diff before committing — in particular check that the namespace (`GoPay\Payments\Generated\Model`) and `ModelInterface` compatibility are preserved.

Do **not** edit files in `src/Generated/` by hand. If a model class is wrong, fix the upstream spec.

---

## License

MIT

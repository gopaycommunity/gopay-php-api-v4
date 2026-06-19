# Changelog

## [Unreleased]

### Added
- Initial release of GoPay PHP SDK for Payments API v4
- `GoPayClient` — flat public API surface: all methods on one class
- PSR-18 transport with auto-discovery via `php-http/discovery`
- OAuth2 `client_credentials` grant with transparent token refresh and 401-retry
- `PaymentsApi`: `createPayment`, `chargePayment`, `getPaymentStatus`, `getChargeState`, `awaitChargeState`, Google Pay / Apple Pay / QR payment support
- `CardsApi`: `getCardDetails`, `deleteCard`, `tokenizeEncryptedCard` (browser iframe JWE)
- `RecurrencesApi`: `createRecurrence`, `recurrenceStatus`, `startRecurrence`, `recurrenceNext`, `stopRecurrence`
- `RefundsApi`: `refundPayment`, `listRefunds`, `getRefund`
- `LinksApi`: `createPaymentLink`, `linkStatus`, `disableLink`
- `AuthApi`: `authenticate`, `isAuthenticated`, `logout`, `setShareableKey`, `getBrowserKeys`
- Typed response DTOs: `PaymentDetails`, `PaymentChargeResponse`, `PaymentChargeStatusResponse`, `PermanentCardTokenDetails`, `RecurrenceDetails`, `RefundDetails`, `LinkDetails`, `QrPaymentDetails`
- `GoPaySdkException` + `GoPayHttpException` with `ErrorCode` enum
- `onError` callback for centralized error handling
- Shareable-key Basic auth fallback for browser SDK compatibility
- PHPStan level 10, php-cs-fixer PER-CS ruleset, PHPUnit 10 test suite
- Migration guide (`MIGRATION.md`) for v3 → v4 consumers

This SDK targets **GoPay Payments API v4** only. It is not source-level compatible with `gopay/payments-sdk` v1 (API v3). See `MIGRATION.md`.

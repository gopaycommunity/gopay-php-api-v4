## [1.0.1](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.0.0...1.0.1) (2026-06-29)

### Bug Fixes

* install git in the GitHub sync step of the pipeline GPOMA-2291 ([3b2a78d](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/3b2a78dceaea59a5654559900c5b7a6c9b843ee4))

## 1.0.0 (2026-06-29)

### Features

* **GPOMA-2291:** add example scripts and E2E test suite ([4088e1e](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/4088e1e774edd9b3468ca77db801d9129f635fdf))
* **GPOMA-2291:** enable codegen via openapi-generator-cli@2.39 + ObjectSerializer ([37a33a4](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/37a33a4e96dc6b8aae9b65e744d0e9ac9c831357))
* **GPOMA-2291:** implement PHP SDK for GoPay Payments API v4 ([c2bfe28](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/c2bfe28d9ebeb4498c9541970e0534c03b866465))

### Bug Fixes

* add conventional-changelog-conventionalcommits package to semantic-release steps GPOMA-2291 ([9800fd5](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/9800fd50a1d5daa2eb9217cdfe79d1ea366cd102))
* add note about generated files GPOMA-2291 ([c6eec76](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/c6eec76f0f4a5baacd0852e263ffe8956da3d1eb))
* address David Kolář review comments (2026-06-26) GPOMA-2291 ([ab7d18c](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/ab7d18c98f58a9bbbc91caad6f099d904caaac80))
* enable packagist push GPOMA-2291 ([fb8e362](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/fb8e362d0fbfbc579bf94d3b6b0bf7570e146bb4))
* **GPOMA-2291:** add CANCELLED to ChargeState spec enum and regenerate model ([4f490a8](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/4f490a85c3ce9a5cf9086d3f6bf95a0598c8664a))
* **GPOMA-2291:** address CodeRabbit findings — onError validation, auth guards, JSON fail-fast, polling fixes ([7f5ea1f](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/7f5ea1f89ba0b97814dd617c7af11b5a0057baef))
* **GPOMA-2291:** address David Kolář PR review — auth & error code improvements ([b6b3b36](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/b6b3b363113fc9cc109c3727be9b7d0acf007d37))
* **GPOMA-2291:** address David Kolář review comments (round 3) ([fd850a8](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/fd850a802c0ce3f90c58e30be3bde3522d5c7859))
* **GPOMA-2291:** address PR review feedback from David Kolář ([d4b33af](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/d4b33af5f18bb6263168eef7ed8979b286dfb75e))
* **GPOMA-2291:** correct GitHub mirror repo URL to gopay-php-api-v4 ([f273d76](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/f273d764681946630dca039b40c72d1109cfed8b))
* **GPOMA-2291:** remove docker-compose.yml and Dockerfile ([76c45a8](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/76c45a8680d93b6f1c50ca378fd0a4e3fa13385f))
* **GPOMA-2291:** remove invented CANCELLED charge state, use FAILED per spec ([b0b8fa1](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/b0b8fa19fa54439281419cd91879b5e5d62729d1))
* **GPOMA-2291:** remove Stoplight mock server references, use sandbox by default ([bf8d78d](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/bf8d78dcc7a9ea52ffca7913fe8abae302589500))
* **GPOMA-2291:** rename QrPaymentDetails.php → QRPaymentDetails.php for case-sensitive Linux CI ([a6fa15a](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/a6fa15adf2eda6bba88b91b69d0c911190facdcf))
* **GPOMA-2291:** resolve PHPStan level-9 and php-cs-fixer issues ([9e09482](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/9e094824fcad20d05a04361eb56275e899a1eb9c))
* **GPOMA-2291:** resolve SonarCloud code smells in AuthHandler and HttpClient ([8ad1b63](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/8ad1b639e0c63c1829b2658e7c325fc416471099))
* **GPOMA-2291:** resolve SonarCloud S1488 — inline [@var](https://bitbucket.org/var) cast on return, drop temp vars ([8bbf87c](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/8bbf87c4cade79b10d2fcd1b4ad3037b191719d6))
* **GPOMA-2291:** upgrade PHPStan 1→2, enable level 10, fix two new errors ([367f91a](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/367f91a59b5300f53a0db4336483e7cb8e0d7e0f))
* improve resiliency from audit result GPOMA-2291 ([b7bb0d5](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/b7bb0d5f7494c2ed5904bd274d1588a77beae41d))
* improve test coverage GPOMA-2291 ([72f5e0d](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/72f5e0d5200cb00926b01a393a42f836ed9cb411))
* readme packagist link GPOMA-2291 ([cbe1d8c](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/cbe1d8c191b0064c26be66f83f89d1f419dd1e54))
* remove agentic pipelines GPOMA-2291 ([c273dcc](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/c273dcc6ca9e51657be386ee18a05ad0f7a89256))
* remove packagist manual sync GPOMA-2291 ([61f50d9](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/61f50d9e0a7a3e0fe7799ff9afb3cade008c8efa))
* rename env variables for clarity GPOMA-2291 ([ba149e5](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/ba149e5503d48e605a272ebac6b5b708bb5f717a))
* update claude.md GPOMA-2291 ([59a8a9b](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/59a8a9bd0d5e102d691dc10cc9cb1e88697c5f06))
* update spec url to production GPOMA-2291 ([a2634d9](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/a2634d9b24f6c16257ec4dc705ef30f2ff18fe40))
* use production api url GPOMA-2291 ([55ee7ea](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/55ee7eacb17d92b3b2e733214e64c0f9949043a6))

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
- Typed response DTOs: `PaymentDetails`, `PaymentChargeResponse`, `PaymentChargeStatusResponse`, `PermanentCardTokenDetails`, `RecurrenceDetails`, `RefundDetails`, `LinkDetails`, `QRPaymentDetails`
- `GoPaySdkException` + `GoPayHttpException` with `ErrorCode` enum
- `onError` callback for centralized error handling
- Shareable-key Basic auth fallback for browser SDK compatibility
- PHPStan level 10, php-cs-fixer PER-CS ruleset, PHPUnit 10 test suite
- Migration guide (`MIGRATION.md`) for v3 → v4 consumers

This SDK targets **GoPay Payments API v4** only. It is not source-level compatible with `gopay/payments-sdk` v1 (API v3). See `MIGRATION.md`.

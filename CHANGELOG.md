## [1.3.0](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.2.0...1.3.0) (2026-08-20)

### Features

* **refunds:** add awaitRefundState GPOMA-2522 ([0340bd5](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/0340bd5d536661d2d3f9b660f9a0683b103b8854))

### Bug Fixes

* **refunds:** address awaitRefundState review GPOMA-2542 ([72df7ce](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/72df7ce377cdf937de388e3cd573608b93680ab6)), closes [#10](https://bitbucket.org/gp-gopay/gopay-php-api-v4/issues/10)

## [1.2.0](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.1.0...1.2.0) (2026-08-20)

### Features

* **refunds:** restore refunds support GPOMA-2522 ([2193ddf](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/2193ddfc2554df5090dce53a0239351e5558706b))

### Bug Fixes

* **codegen:** fail closed if the mock server survives stripping GPOMA-2522 ([9a4d1f4](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/9a4d1f4c218ac69ad9a1d793c5ef29d453ef9273))
* **codegen:** strip the beta-injected mock server from the vendored spec GPOMA-2522 ([3fac61b](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/3fac61b0034518b91526b4e6b3f5db451b8c00e8))
* **examples:** never report a FAILED refund as success GPOMA-2522 ([6a84960](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/6a84960321877aba6bf4c028444e38e26dd3d708))
* **http:** reject nested arrays in decodeJsonList GPOMA-2522 ([cc89067](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/cc8906705b9e5be16719453291fcd93c4b347760))
* **refunds:** address self-review findings GPOMA-2522 ([841d1e3](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/841d1e3d4dc9e2609dbd2adb7556b446d620f637))
* resolve SonarCloud S1488 in decodeJsonList GPOMA-2522 ([87f8279](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/87f82793ec40ee1f4f0569dd9b9bb6d055cc24e9))

## [1.1.0](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.0.4...1.1.0) (2026-08-01)

### Features

* require accept_header in browser_data GPOMA-2474 ([0ae0a27](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/0ae0a271e20bf2c26c694cb8c0f520af416867a6))
* require user_agent and javascript_enabled in browser_data GPOMA-2474 ([6d12afc](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/6d12afcbf91b7ff70773e2a99ce4a737950ab763)), closes [#18](https://bitbucket.org/gp-gopay/gopay-php-api-v4/issues/18)

### Bug Fixes

* skip non-UTF-8 Accept header values instead of failing the charge GPOMA-2474 ([00f6ec8](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/00f6ec877321fd4b626596dc9557bb861ca9e8b4))

## [1.0.4](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.0.3...1.0.4) (2026-07-24)

### Bug Fixes

* address CodeRabbit review comment GPOMA-2453 ([d97d57b](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/d97d57b213afad765266fe5585e4edee32757f47))
* remove refunds/recurrences/payment-links (unfinished endpoints removed from schema) GPOMA-2453 ([69fa5cc](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/69fa5ccd8021e748a7782acf74671aff87f7af44))
* remove refunds/recurrences/payment-links (unfinished endpoints removed from schema) GPOMA-2453 ([c637225](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/c6372252474c28ea8d68bed056a2a09dc84202b0))

## [1.0.3](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.0.2...1.0.3) (2026-07-20)

### Bug Fixes

* fetch OpenAPI spec from beta source instead of stale public docs GPOMA-2418 ([9becb79](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/9becb79028532ec1e4f424d0346d8ac3af66455a))
* refresh spec/payments.yaml from beta source and regenerate models GPOMA-2418 ([f4e0e9f](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/f4e0e9fbb32204f072e50128b78908d63220ad3a))
* stamp generated files with public docs URL, not the beta fetch source GPOMA-2418 ([4a37dd0](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/4a37dd06bfedc40748dd1db82d478448e11d1442))
* update sandbox/production base URLs to new gp-gw gateway GPOMA-2418 ([4ac21bc](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/4ac21bccd4394f96be4401dc553191c0f6be3340))

## [1.0.2](https://bitbucket.org/gp-gopay/gopay-php-api-v4/compare/1.0.1...1.0.2) (2026-07-09)

### Bug Fixes

* rename composer package to gopaycommunity/gopay-php-api-v4, add README badges GPOMA-2403 ([f1ac7f7](https://bitbucket.org/gp-gopay/gopay-php-api-v4/commit/f1ac7f78d9c9a79553394be60eeeb9304be19e0c))

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
- `AuthApi`: `authenticate`, `isAuthenticated`, `logout`, `setShareableKey`, `getBrowserKeys`
- Typed response DTOs: `PaymentDetails`, `PaymentChargeResponse`, `PaymentChargeStatusResponse`, `PermanentCardTokenDetails`, `QRPaymentDetails`
- `GoPaySdkException` + `GoPayHttpException` with `ErrorCode` enum
- `onError` callback for centralized error handling
- Shareable-key Basic auth fallback for browser SDK compatibility
- PHPStan level 10, php-cs-fixer PER-CS ruleset, PHPUnit 10 test suite
- Migration guide (`MIGRATION.md`) for v3 → v4 consumers

This SDK targets **GoPay Payments API v4** only. It is not source-level compatible with `gopay/payments-sdk` v1 (API v3). See `MIGRATION.md`.

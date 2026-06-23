<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\E2E;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\GoPayClient;
use PHPUnit\Framework\TestCase;

/**
 * Base class for E2E tests that hit a real (or mock) GoPay API.
 *
 * Requires a .env.e2e file in the project root (gitignored).
 * All tests in subclasses are skipped automatically if credentials are absent.
 *
 * Run: phpunit --group e2e
 */
abstract class E2ETestCase extends TestCase
{
    protected GoPayClient $sdk;
    protected string $goid;

    protected function setUp(): void
    {
        parent::setUp();
        self::loadEnvFile(__DIR__ . '/../../.env.e2e');
        $this->requireCredentials();

        $baseUrl      = self::env('GOPAY_PAYMENTS_V4_BASE_URL');
        $shareableKey = self::env('GOPAY_PAYMENTS_V4_SHAREABLE_KEY');

        $config = new Config(
            environment: Environment::Sandbox,
            baseUrl: $baseUrl,
            shareableKey: $shareableKey,
        );

        $this->sdk  = new GoPayClient($config);
        $this->goid = self::requireEnv('GOPAY_PAYMENTS_V4_GOID');

        $this->sdk->authenticate(
            self::requireEnv('GOPAY_PAYMENTS_V4_CLIENT_ID'),
            self::requireEnv('GOPAY_PAYMENTS_V4_CLIENT_SECRET'),
            'payment:write payment:read card:write card:read',
        );
    }

    // -------------------------------------------------------------------------

    private function requireCredentials(): void
    {
        if (self::env('GOPAY_PAYMENTS_V4_CLIENT_ID') === null) {
            self::markTestSkipped(
                'E2E credentials not configured. '
                . 'Copy .env.e2e.example to .env.e2e and fill in GOPAY_PAYMENTS_V4_* values.',
            );
        }
    }

    protected static function env(string $key): ?string
    {
        $fromEnv = getenv($key);

        return $_ENV[$key] ?? ($fromEnv !== false ? $fromEnv : null);
    }

    protected static function requireEnv(string $key): string
    {
        $val = self::env($key);
        if ($val === null || $val === '') {
            self::fail("Missing required E2E env var: {$key}");
        }

        return $val;
    }

    private static function loadEnvFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2) + [1 => ''];
            $key = trim($key);
            $val = trim($val, " \t\"'");
            if ($key !== '' && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
            }
        }
    }
}

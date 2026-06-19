<?php

declare(strict_types=1);

/**
 * Shared bootstrap for all example scripts.
 *
 * Usage: require __DIR__ . '/bootstrap.php';
 * Provides: $sdk (GoPayClient), $goid (string), $shareableKey (?string)
 *
 * Reads credentials from examples/.env (copy from .env.example).
 * Falls back to shell environment variables so it works in CI too.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Run `composer install` first.\n");
    exit(1);
}

require $autoload;

// Minimal .env loader — no extra dependency needed.
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

function env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? (getenv($key) ?: $default);
}

function env_require(string $key): string
{
    $val = env($key);
    if ($val === null || $val === '') {
        fwrite(STDERR, "Missing required env var: {$key}\n");
        fwrite(STDERR, "Copy examples/.env.example to examples/.env and fill in your credentials.\n");
        exit(1);
    }

    return $val;
}

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\GoPayClient;

$baseUrl      = env('GP_PHP_SDK_BASE_URL');
$shareableKey = env('GP_PHP_SDK_SHAREABLE_KEY');
$goid         = env_require('GP_PHP_SDK_GOID');

$config = new Config(
    environment: Environment::Sandbox,
    baseUrl: $baseUrl,
    shareableKey: $shareableKey,
);

$sdk = new GoPayClient($config);

$sdk->authenticate(
    env_require('GP_PHP_SDK_CLIENT_ID'),
    env_require('GP_PHP_SDK_CLIENT_SECRET'),
    'payment:write payment:read card:write card:read',
);

echo '[GoPaySDK] Authenticated. Ready.' . PHP_EOL;

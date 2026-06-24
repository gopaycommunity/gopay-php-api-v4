<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\Config;
use GoPay\Payments\Environment;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function defaultsUseSandboxEnvironment(): void
    {
        $config = new Config();
        $this->assertSame(Environment::Sandbox, $config->environment);
        $this->assertNull($config->baseUrl);
        $this->assertFalse($config->debugLoggingEnabled);
        $this->assertNull($config->onError);
        $this->assertNull($config->shareableKey);
    }

    #[Test]
    public function resolvedBaseUrlReturnsSandboxUrlWhenNoBaseUrlSet(): void
    {
        $config = new Config(environment: Environment::Sandbox);
        $this->assertSame(
            'https://api.sandbox.gopay.com/api/merchant/payments/4.0',
            $config->resolvedBaseUrl(),
        );
    }

    #[Test]
    public function resolvedBaseUrlReturnsProductionUrlForProductionEnvironment(): void
    {
        $config = new Config(environment: Environment::Production);
        $this->assertSame(
            'https://api.gopay.com/api/merchant/payments/4.0',
            $config->resolvedBaseUrl(),
        );
    }

    #[Test]
    public function resolvedBaseUrlPrefersExplicitBaseUrlOverEnvironment(): void
    {
        $config = new Config(
            environment: Environment::Sandbox,
            baseUrl: 'https://custom.example.com/api',
        );
        $this->assertSame('https://custom.example.com/api', $config->resolvedBaseUrl());
    }

    #[Test]
    public function constructorAcceptsValidCallableOnError(): void
    {
        $config = new Config(onError: static function (\Throwable $e): void {});
        $this->assertIsCallable($config->onError);
    }

    #[Test]
    public function constructorThrowsWhenOnErrorIsNotCallable(): void
    {
        $this->expectException(GoPaySdkException::class);
        $this->expectExceptionMessage('onError must be a callable');
        // @phpstan-ignore-next-line
        new Config(onError: 'not-a-callable');
    }

    #[Test]
    public function constructorThrowsWithInvalidConfigErrorCode(): void
    {
        try {
            // @phpstan-ignore-next-line
            new Config(onError: 42);
            $this->fail('Expected GoPaySdkException was not thrown.');
        } catch (GoPaySdkException $e) {
            $this->assertSame(ErrorCode::InvalidConfig, $e->errorCode);
        }
    }

    #[Test]
    public function shareableKeyIsStoredAsGiven(): void
    {
        $config = new Config(shareableKey: 'sk_test_abc');
        $this->assertSame('sk_test_abc', $config->shareableKey);
    }

    #[Test]
    public function debugLoggingFlagIsStoredAsGiven(): void
    {
        $config = new Config(debugLoggingEnabled: true);
        $this->assertTrue($config->debugLoggingEnabled);
    }
}

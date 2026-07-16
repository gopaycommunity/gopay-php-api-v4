<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\Environment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    #[Test]
    public function sandboxBaseUrlPointsToSandboxApi(): void
    {
        $this->assertSame(
            'https://gw.sandbox.gopay.com/gp-gw/api/4.0',
            Environment::Sandbox->baseUrl(),
        );
    }

    #[Test]
    public function productionBaseUrlPointsToProductionApi(): void
    {
        $this->assertSame(
            'https://gate.gopay.com/gp-gw/api/4.0',
            Environment::Production->baseUrl(),
        );
    }

    #[Test]
    public function sandboxHasCorrectBackingValue(): void
    {
        $this->assertSame('sandbox', Environment::Sandbox->value);
    }

    #[Test]
    public function productionHasCorrectBackingValue(): void
    {
        $this->assertSame('production', Environment::Production->value);
    }

    #[Test]
    public function canBeCreatedFromBackingValue(): void
    {
        $this->assertSame(Environment::Sandbox, Environment::from('sandbox'));
        $this->assertSame(Environment::Production, Environment::from('production'));
    }
}

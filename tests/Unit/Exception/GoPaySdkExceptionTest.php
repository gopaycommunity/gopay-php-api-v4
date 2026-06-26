<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Exception;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GoPaySdkExceptionTest extends TestCase
{
    #[Test]
    public function messageIsStored(): void
    {
        $e = new GoPaySdkException('Something went wrong.', ErrorCode::InvalidConfig);
        $this->assertSame('Something went wrong.', $e->getMessage());
    }

    #[Test]
    public function errorCodeIsAccessible(): void
    {
        $e = new GoPaySdkException('Auth failed.', ErrorCode::AuthTokenMissing);
        $this->assertSame(ErrorCode::AuthTokenMissing, $e->errorCode);
    }

    #[Test]
    public function previousExceptionIsChained(): void
    {
        $previous = new \RuntimeException('original error');
        $e = new GoPaySdkException('Wrapped error.', ErrorCode::NetworkError, $previous);
        $this->assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function previousExceptionIsNullByDefault(): void
    {
        $e = new GoPaySdkException('No previous.', ErrorCode::InvalidArgument);
        $this->assertNull($e->getPrevious());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $e = new GoPaySdkException('Test.', ErrorCode::ChargeFailed);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    #[Test]
    public function exceptionCodeIsZero(): void
    {
        $e = new GoPaySdkException('Test.', ErrorCode::ChargeTimeout);
        $this->assertSame(0, $e->getCode());
    }

    #[Test]
    public function allErrorCodesCanBeUsed(): void
    {
        $codes = [
            ErrorCode::AuthTokenMissing,
            ErrorCode::AuthRefreshFailed,
            ErrorCode::AuthInvalidResponse,
            ErrorCode::AuthCredentialsMissing,
            ErrorCode::AuthUnauthorized,
            ErrorCode::NetworkError,
            ErrorCode::ChargeTimeout,
            ErrorCode::ChargeFailed,
            ErrorCode::InvalidConfig,
            ErrorCode::InvalidArgument,
        ];

        foreach ($codes as $code) {
            $e = new GoPaySdkException('Test.', $code);
            $this->assertSame($code, $e->errorCode);
        }
    }
}

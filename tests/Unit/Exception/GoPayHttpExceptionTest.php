<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Exception;

use GoPay\Payments\Exception\GoPayHttpException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GoPayHttpExceptionTest extends TestCase
{
    #[Test]
    public function messageContainsHttpStatusCode(): void
    {
        $e = new GoPayHttpException(404, null);
        $this->assertSame('GoPay API error: HTTP 404', $e->getMessage());
    }

    #[Test]
    public function statusCodeIsAccessible(): void
    {
        $e = new GoPayHttpException(422, ['error' => 'invalid']);
        $this->assertSame(422, $e->status);
    }

    #[Test]
    public function bodyIsAccessibleAsArray(): void
    {
        $body = ['error' => 'unprocessable', 'code' => 'INVALID_AMOUNT'];
        $e = new GoPayHttpException(422, $body);
        $this->assertSame($body, $e->body);
    }

    #[Test]
    public function bodyCanBeNull(): void
    {
        $e = new GoPayHttpException(500, null);
        $this->assertNull($e->body);
    }

    #[Test]
    public function bodyCanBeString(): void
    {
        $e = new GoPayHttpException(503, 'Service Unavailable');
        $this->assertSame('Service Unavailable', $e->body);
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $e = new GoPayHttpException(401, null);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    #[Test]
    public function exceptionCodeIsZero(): void
    {
        $e = new GoPayHttpException(500, null);
        // RuntimeException code — not HTTP status code
        $this->assertSame(0, $e->getCode());
    }
}

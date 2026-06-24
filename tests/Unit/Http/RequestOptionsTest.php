<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Http;

use GoPay\Payments\Http\RequestOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestOptionsTest extends TestCase
{
    #[Test]
    public function defaultsHaveEmptyHeadersAndNullAccessToken(): void
    {
        $opts = new RequestOptions();
        $this->assertSame([], $opts->headers);
        $this->assertNull($opts->accessToken);
    }

    #[Test]
    public function headersAreStored(): void
    {
        $opts = new RequestOptions(headers: ['X-Custom' => 'value', 'Origin' => 'https://example.com']);
        $this->assertSame(['X-Custom' => 'value', 'Origin' => 'https://example.com'], $opts->headers);
    }

    #[Test]
    public function accessTokenIsStored(): void
    {
        $opts = new RequestOptions(accessToken: 'override-token-abc');
        $this->assertSame('override-token-abc', $opts->accessToken);
    }

    #[Test]
    public function headersAndAccessTokenCanBeSetTogether(): void
    {
        $opts = new RequestOptions(
            headers: ['X-Trace' => 'trace-id'],
            accessToken: 'my-token',
        );
        $this->assertSame(['X-Trace' => 'trace-id'], $opts->headers);
        $this->assertSame('my-token', $opts->accessToken);
    }
}

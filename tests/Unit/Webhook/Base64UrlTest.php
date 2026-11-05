<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit\Webhook;

use GoPay\Payments\Webhook\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    #[Test]
    public function round_trips_bytes_unpadded(): void
    {
        $bytes = "\xfb\xff\xbf\x00ABC";

        $encoded = Base64Url::encode($bytes);

        $this->assertSame('-_-_AEFCQw', $encoded);
        $this->assertSame($bytes, Base64Url::decode($encoded));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notStrictBase64Url(): iterable
    {
        yield 'empty' => [''];
        // Length 4n with the newline: strict base64_decode() skips it, so only the pattern can refuse it.
        yield 'trailing newline' => ["QUI\n"];
        yield 'trailing CRLF' => ["QU\r\n"];
        yield 'leading newline' => ["\nQUJD"];
        yield 'inner space' => ['QU JD'];
        yield 'padding' => ['QUI='];
        yield 'standard alphabet' => ['ab+/'];
        yield 'length 4n + 1' => ['AAAAA'];
    }

    #[Test]
    #[DataProvider('notStrictBase64Url')]
    public function refuses_anything_but_strict_unpadded_base64url(string $value): void
    {
        $this->assertNull(Base64Url::decode($value));
    }
}

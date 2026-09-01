<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\AcceptHeader;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AcceptHeaderTest extends TestCase
{
    private const EXPECTED = '{"accept":"application/json, text/plain, */*","accept-language":"cs;q=0.5","accept-encoding":"gzip, deflate, br, zstd"}';

    public function testFromServerRequestEncodesAllThreeHeaders(): void
    {
        $request = new ServerRequest('POST', '/charge', [
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'cs;q=0.5',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
        ]);

        $this->assertSame(self::EXPECTED, AcceptHeader::fromServerRequest($request));
    }

    public function testFromServerRequestOmitsAbsentHeaders(): void
    {
        $request = new ServerRequest('POST', '/charge', ['Accept' => 'text/html']);

        $this->assertSame('{"accept":"text/html"}', AcceptHeader::fromServerRequest($request));
    }

    public function testFromServerRequestWithoutAcceptHeadersYieldsEmptyJsonObject(): void
    {
        $this->assertSame('{}', AcceptHeader::fromServerRequest(new ServerRequest('GET', '/')));
    }

    /**
     * Header values arrive straight off the wire, so a client can send bytes that
     * are not valid UTF-8. Those must be dropped rather than reaching json_encode()
     * and aborting the entire charge with a JsonException.
     */
    public function testFromServerRequestDropsInvalidUtf8HeaderValues(): void
    {
        $request = new ServerRequest('POST', '/charge', [
            'Accept'          => "text/html\xB1\x31",
            'Accept-Language' => 'cs-CZ',
        ]);

        $this->assertSame('{"accept-language":"cs-CZ"}', AcceptHeader::fromServerRequest($request));
    }

    public function testFromServerRequestWithOnlyInvalidUtf8YieldsEmptyJsonObject(): void
    {
        $request = new ServerRequest('POST', '/charge', ['Accept' => "\xB1\x31"]);

        $this->assertSame('{}', AcceptHeader::fromServerRequest($request));
    }

    public function testFromServerRequestPreservesValidMultibyteUtf8(): void
    {
        $request = new ServerRequest('POST', '/charge', [
            'Accept'          => 'text/html',
            'Accept-Language' => 'cs-CZ,čeština',
        ]);

        $this->assertSame(
            '{"accept":"text/html","accept-language":"cs-CZ,čeština"}',
            AcceptHeader::fromServerRequest($request),
        );
    }

    public function testResultDecodesBackToTheHeaderMap(): void
    {
        $request = new ServerRequest('POST', '/charge', [
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'cs;q=0.5',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
        ]);

        $decoded = json_decode(AcceptHeader::fromServerRequest($request), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'accept'          => 'application/json, text/plain, */*',
                'accept-language' => 'cs;q=0.5',
                'accept-encoding' => 'gzip, deflate, br, zstd',
            ],
            $decoded,
        );
    }
}

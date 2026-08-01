<?php

declare(strict_types=1);

namespace GoPay\Payments\Tests\Unit;

use GoPay\Payments\AcceptHeader;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AcceptHeaderTest extends TestCase
{
    private const SERVER = [
        'HTTP_ACCEPT'          => 'application/json, text/plain, */*',
        'HTTP_ACCEPT_LANGUAGE' => 'cs;q=0.5',
        'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br, zstd',
    ];

    private const EXPECTED = '{"accept":"application/json, text/plain, */*","accept-language":"cs;q=0.5","accept-encoding":"gzip, deflate, br, zstd"}';

    public function testFromServerGlobalsEncodesAllThreeHeaders(): void
    {
        $this->assertSame(self::EXPECTED, AcceptHeader::fromServerGlobals(self::SERVER));
    }

    public function testFromServerGlobalsOmitsAbsentHeaders(): void
    {
        $this->assertSame(
            '{"accept":"text/html"}',
            AcceptHeader::fromServerGlobals(['HTTP_ACCEPT' => 'text/html', 'REQUEST_METHOD' => 'POST']),
        );
    }

    public function testFromServerGlobalsIgnoresEmptyAndNonStringValues(): void
    {
        $this->assertSame(
            '{"accept-language":"en"}',
            AcceptHeader::fromServerGlobals([
                'HTTP_ACCEPT'          => '',
                'HTTP_ACCEPT_LANGUAGE' => 'en',
                'HTTP_ACCEPT_ENCODING' => 42,
            ]),
        );
    }

    public function testFromServerGlobalsWithNoAcceptHeadersYieldsEmptyJsonObject(): void
    {
        $this->assertSame('{}', AcceptHeader::fromServerGlobals([]));
    }

    /**
     * Header values arrive straight off the wire, so a client can send bytes that
     * are not valid UTF-8. Those must be dropped rather than reaching json_encode()
     * and aborting the entire charge with a JsonException.
     */
    public function testFromServerGlobalsDropsInvalidUtf8HeaderValues(): void
    {
        $this->assertSame(
            '{"accept-language":"cs;q=0.5"}',
            AcceptHeader::fromServerGlobals([
                'HTTP_ACCEPT'          => "text/html\xB1\x31",
                'HTTP_ACCEPT_LANGUAGE' => 'cs;q=0.5',
            ]),
        );
    }

    public function testFromServerGlobalsWithOnlyInvalidUtf8YieldsEmptyJsonObject(): void
    {
        $this->assertSame('{}', AcceptHeader::fromServerGlobals(['HTTP_ACCEPT' => "\xB1\x31"]));
    }

    public function testFromServerGlobalsPreservesValidMultibyteUtf8(): void
    {
        $this->assertSame(
            '{"accept":"text/html","accept-language":"cs-CZ,čeština"}',
            AcceptHeader::fromServerGlobals([
                'HTTP_ACCEPT'          => 'text/html',
                'HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,čeština',
            ]),
        );
    }

    public function testFromServerGlobalsDefaultsToServerSuperglobal(): void
    {
        $backup = $_SERVER;

        try {
            $_SERVER['HTTP_ACCEPT']          = 'text/html';
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'cs-CZ';
            unset($_SERVER['HTTP_ACCEPT_ENCODING']);

            $this->assertSame(
                '{"accept":"text/html","accept-language":"cs-CZ"}',
                AcceptHeader::fromServerGlobals(),
            );
        } finally {
            $_SERVER = $backup;
        }
    }

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

    public function testFromServerRequestDropsInvalidUtf8HeaderValues(): void
    {
        $request = new ServerRequest('POST', '/charge', [
            'Accept'          => "text/html\xB1\x31",
            'Accept-Language' => 'cs-CZ',
        ]);

        $this->assertSame('{"accept-language":"cs-CZ"}', AcceptHeader::fromServerRequest($request));
    }

    public function testResultDecodesBackToTheHeaderMap(): void
    {
        $decoded = json_decode(AcceptHeader::fromServerGlobals(self::SERVER), true, 512, JSON_THROW_ON_ERROR);

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

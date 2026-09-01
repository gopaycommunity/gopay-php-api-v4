<?php

declare(strict_types=1);

namespace GoPay\Payments;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Encodes Accept headers into the JSON shape the `accept_header` field of
 * `browser_data` uses.
 *
 * **Not for a card charge.** EMV 3DS wants the headers of the *customer's*
 * browser, and `browser_data.accept_header` must come from
 * `GET /cards/browser-data` fetched by that browser, alongside `ip` and
 * `user_agent`. Building the value from a request your server received produces
 * the server's own headers — a payload that looks valid and then fails
 * authentication at the issuer. `fromServerGlobals()` existed for exactly that
 * and has been removed.
 *
 * What remains is the encoder, for a caller that already holds the customer's
 * own request — a gateway or edge worker forwarding it, say. The result is a
 * JSON object with up to three keys — `accept`, `accept-language`,
 * `accept-encoding` — omitting any header that is absent or not valid UTF-8.
 * Slashes are not escaped:
 *
 *   {"accept":"application/json, text/plain","accept-language":"cs;q=0.5","accept-encoding":"gzip, deflate, br, zstd"}
 *
 * With none of them usable the result is `{}` — a well-formed value carrying no
 * 3DS signal, which is a reason to check where the headers were lost rather
 * than to send it.
 */
final class AcceptHeader
{
    /** The headers forwarded, in the order they appear in the encoded object. */
    private const HEADERS = ['accept', 'accept-language', 'accept-encoding'];

    /**
     * Builds the accept_header string from a PSR-7 server request.
     *
     * @return string JSON object of the captured headers, or `{}` if none were usable.
     */
    public static function fromServerRequest(ServerRequestInterface $request): string
    {
        $headers = [];
        foreach (self::HEADERS as $jsonKey) {
            $value = $request->getHeaderLine($jsonKey);
            if (self::isForwardable($value)) {
                $headers[$jsonKey] = $value;
            }
        }

        return self::encode($headers);
    }

    /**
     * A header value is forwarded only if it is non-empty and valid UTF-8.
     *
     * Header values come straight off the wire, so a client can put arbitrary
     * bytes in them. Without this check the malformed value would reach
     * json_encode() and abort the whole charge with a JsonException.
     *
     * `preg_match('//u', …)` is the UTF-8 validity test rather than
     * mb_check_encoding() so the SDK keeps working without ext-mbstring,
     * which it does not otherwise require.
     */
    private static function isForwardable(string $value): bool
    {
        return $value !== '' && preg_match('//u', $value) === 1;
    }

    /** @param array<string, string> $headers */
    private static function encode(array $headers): string
    {
        return json_encode(
            $headers,
            JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}

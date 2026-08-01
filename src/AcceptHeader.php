<?php

declare(strict_types=1);

namespace GoPay\Payments;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the `accept_header` value required in `browser_data` of a card charge.
 *
 * EMV 3DS wants the Accept headers of the *customer's* browser. The canonical
 * source is server-side capture: read them from the incoming HTTP request the
 * customer's browser made to your server, at the moment you call chargePayment().
 *
 * The result is a JSON-encoded object with up to three keys — `accept`,
 * `accept-language`, `accept-encoding` — omitting any header the browser did
 * not send, as well as any whose value is not valid UTF-8. Slashes are not
 * escaped:
 *
 *   {"accept":"application/json, text/plain","accept-language":"cs;q=0.5","accept-encoding":"gzip, deflate, br, zstd"}
 *
 * If the request carried none of these headers — a proxy stripped them, or
 * every value was malformed — the result is the empty object `{}`. That is a
 * valid value for the required `accept_header` field, but it carries no 3DS
 * signal: capture the headers earlier in the chain if the issuer needs them.
 *
 * Usage inside a charge request handler:
 * ```php
 * $charge = $sdk->chargePayment($paymentId, [
 *     'payment_instrument' => [
 *         'payment_instrument' => 'PAYMENT_CARD',
 *         'input'              => ['input_type' => 'CARD_TOKEN', 'card_token' => $token],
 *         'browser_data'       => [
 *             // … language, timezone, screen_width, screen_height, color_depth …
 *             'accept_header' => AcceptHeader::fromServerGlobals(),
 *             // or, with a PSR-7 stack:
 *             // 'accept_header' => AcceptHeader::fromServerRequest($request),
 *         ],
 *     ],
 * ]);
 * ```
 */
final class AcceptHeader
{
    /** JSON key → $_SERVER key for each forwarded header. */
    private const HEADERS = [
        'accept'          => 'HTTP_ACCEPT',
        'accept-language' => 'HTTP_ACCEPT_LANGUAGE',
        'accept-encoding' => 'HTTP_ACCEPT_ENCODING',
    ];

    /**
     * Builds the accept_header string from a $_SERVER-shaped array.
     *
     * @param array<string, mixed>|null $server Defaults to the $_SERVER superglobal of the current request — the one the customer's browser made.
     *
     * @return string JSON object of the captured headers, or `{}` if none were usable.
     */
    public static function fromServerGlobals(?array $server = null): string
    {
        $server ??= $_SERVER;

        $headers = [];
        foreach (self::HEADERS as $jsonKey => $serverKey) {
            $value = $server[$serverKey] ?? null;
            if (is_string($value) && self::isForwardable($value)) {
                $headers[$jsonKey] = $value;
            }
        }

        return self::encode($headers);
    }

    /**
     * Builds the accept_header string from a PSR-7 server request.
     *
     * @return string JSON object of the captured headers, or `{}` if none were usable.
     */
    public static function fromServerRequest(ServerRequestInterface $request): string
    {
        $headers = [];
        foreach (array_keys(self::HEADERS) as $jsonKey) {
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

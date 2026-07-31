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
 * not send. Example (with `/` unescaped for readability):
 *
 *   {"accept":"application\/json, text\/plain","accept-language":"cs;q=0.5","accept-encoding":"gzip, deflate, br, zstd"}
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
     */
    public static function fromServerGlobals(?array $server = null): string
    {
        $server ??= $_SERVER;

        $headers = [];
        foreach (self::HEADERS as $jsonKey => $serverKey) {
            $value = $server[$serverKey] ?? null;
            if (is_string($value) && $value !== '') {
                $headers[$jsonKey] = $value;
            }
        }

        return self::encode($headers);
    }

    /**
     * Builds the accept_header string from a PSR-7 server request.
     */
    public static function fromServerRequest(ServerRequestInterface $request): string
    {
        $headers = [];
        foreach (array_keys(self::HEADERS) as $jsonKey) {
            $value = $request->getHeaderLine($jsonKey);
            if ($value !== '') {
                $headers[$jsonKey] = $value;
            }
        }

        return self::encode($headers);
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

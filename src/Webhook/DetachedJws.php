<?php

declare(strict_types=1);

namespace GoPay\Payments\Webhook;

/**
 * `X-GoPay-Signature`: a detached JWS (RFC 7515 Appendix F), `<protected header>..<signature>`,
 * with the header `{"alg":"RS256","kid":…}` and the raw request body as the payload.
 *
 * Anything else is refused rather than guessed at.
 *
 * @internal
 */
final class DetachedJws
{
    private function __construct(
        /** The protected header exactly as sent: it is part of the signing input. */
        private readonly string $protectedHeader,
        public readonly string $kid,
        private readonly string $signature,
    ) {}

    /**
     * @param string|list<string>|array<array-key, string>|null $header
     *
     * @throws \UnexpectedValueException with a reason written here, never text from the request
     */
    public static function parse(string|array|null $header): self
    {
        $value = $header;
        if (is_array($header)) {
            // Node-style arrays: exactly one value, or none to verify.
            $value = count($header) === 1 ? reset($header) : null;
        }
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException('exactly one X-GoPay-Signature header is required');
        }

        $parts = explode('.', $value);
        if (count($parts) !== 3 || $parts[1] !== '') {
            throw new \UnexpectedValueException('X-GoPay-Signature is not a detached JWS');
        }
        [$protectedHeader, , $signaturePart] = $parts;
        $headerBytes = Base64Url::decode($protectedHeader);
        $signature = Base64Url::decode($signaturePart);
        if ($headerBytes === null || $signature === null) {
            throw new \UnexpectedValueException('X-GoPay-Signature is not base64url');
        }

        $jose = json_decode($headerBytes);
        if (!$jose instanceof \stdClass) {
            throw new \UnexpectedValueException('the JWS header is not a JSON object');
        }
        if (($jose->alg ?? null) !== 'RS256') {
            throw new \UnexpectedValueException('the JWS header is not RS256');
        }
        $kid = $jose->kid ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new \UnexpectedValueException('the JWS header names no key');
        }
        // `crit` lists extensions a verifier must understand or refuse (RFC 7515 §4.1.11), and
        // `b64: false` (RFC 7797) would change the signing input; GoPay sends neither.
        if (property_exists($jose, 'crit') || property_exists($jose, 'b64')) {
            throw new \UnexpectedValueException('the JWS header uses an unsupported extension');
        }

        return new self($protectedHeader, $kid, $signature);
    }

    public function verify(\OpenSSLAsymmetricKey $key, string $body): bool
    {
        $input = $this->protectedHeader . '.' . Base64Url::encode($body);

        return openssl_verify($input, $this->signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}

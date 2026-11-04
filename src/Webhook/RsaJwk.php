<?php

declare(strict_types=1);

namespace GoPay\Payments\Webhook;

/**
 * Turns a JWK's RSA modulus and exponent into a key `openssl_verify()` accepts.
 *
 * OpenSSL has no JWK import in PHP — `openssl_pkey_new()` given `n` and `e` returns false
 * rather than a public key — so the SubjectPublicKeyInfo DER (RFC 5280 §4.1, RFC 8017 A.1.1)
 * is assembled here and loaded as PEM.
 *
 * @internal
 */
final class RsaJwk
{
    /** DER of AlgorithmIdentifier { rsaEncryption (1.2.840.113549.1.1.1), NULL }. */
    private const RSA_ALGORITHM = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    /**
     * The verifying key of a signing JWK, or null when it is not an RS256 signing key or does
     * not load — one malformed key must not take the others down with it.
     */
    public static function toPublicKey(\stdClass $jwk): ?\OpenSSLAsymmetricKey
    {
        if (
            ($jwk->kty ?? null) !== 'RSA'
            || ($jwk->alg ?? null) !== 'RS256'
            || ($jwk->use ?? null) !== 'sig'
            || !is_string($jwk->n ?? null)
            || !is_string($jwk->e ?? null)
        ) {
            return null;
        }
        $modulus = Base64Url::decode($jwk->n);
        $exponent = Base64Url::decode($jwk->e);
        if ($modulus === null || $exponent === null) {
            return null;
        }

        $rsaPublicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
        // BIT STRING: a leading byte saying no bits of the last octet are unused.
        $spki = self::sequence(self::RSA_ALGORITHM . "\x03" . self::length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? $key : null;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    /** A non-negative INTEGER from big-endian bytes: minimal, with a 0x00 where the top bit is set. */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}

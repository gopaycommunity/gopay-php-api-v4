<?php

declare(strict_types=1);

namespace GoPay\Payments\Webhook;

/**
 * Unpadded base64url (RFC 7515 §2), as JWS and JWK use it.
 *
 * @internal
 */
final class Base64Url
{
    /** The bytes, or null for anything that is not strict unpadded base64url. */
    public static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

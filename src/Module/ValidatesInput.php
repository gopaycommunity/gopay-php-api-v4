<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;

trait ValidatesInput
{
    private function requireNonEmpty(string $value, string $paramName): string
    {
        if (trim($value) === '') {
            throw new GoPaySdkException("[GoPaySDK] {$paramName} must not be empty.", ErrorCode::InvalidArgument);
        }

        return $value;
    }

    /**
     * Validates an identifier that will be interpolated into a request path, and
     * returns it percent-encoded.
     *
     * Ids reach the SDK from merchant databases, admin forms and webhook payloads,
     * and the PSR-7 URI does not escape `/`, `?`, `#` or `.` for us. Interpolated
     * raw, `'../../payments/pay-1'` walks out of its own endpoint and `'1?x=1'`
     * appends a query string — both silently addressing something the caller never
     * asked for. Encoding here keeps every path segment a single segment.
     *
     * Use this for anything that lands between slashes in a path. Values that go
     * into a body or a query string want {@see self::requireNonEmpty()} instead:
     * encoding those would double-encode them further down.
     *
     * Encoding alone is not enough. `.` and `..` are unreserved in RFC 3986, so
     * `rawurlencode()` returns them untouched, and a bare `..` is a dot segment
     * in its own right — `/payments/..` needs no slash of its own to escape the
     * endpoint, and anything that normalises the path resolves it to `/`. Those
     * two exact values are therefore rejected rather than encoded. Longer runs
     * of dots (`...`) are ordinary segments and pass through.
     */
    private function requirePathSegment(string $value, string $paramName): string
    {
        $validated = $this->requireNonEmpty($value, $paramName);

        if ($validated === '.' || $validated === '..') {
            throw new GoPaySdkException(
                "[GoPaySDK] {$paramName} must not be \".\" or \"..\".",
                ErrorCode::InvalidArgument,
            );
        }

        return rawurlencode($validated);
    }
}

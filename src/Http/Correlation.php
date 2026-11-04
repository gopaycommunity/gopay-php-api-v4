<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

use Composer\InstalledVersions;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;

/**
 * The ids GoPay's API v4 logs for every request, so a call can be found from either side.
 *
 * - `X-GoPay-Trace-Id`: one per HTTP request; the 401 retry resends the same request and keeps it.
 * - `X-GoPay-Transaction-Id`: one per public SDK call, shared by every request it makes — the
 *   token refresh, the retry, the polls of an await method — or the merchant's own, set with
 *   {@see \GoPay\Payments\GoPayClient::withTransactionId()}.
 * - `X-GoPay-Payment-Session-Id`: the payment id, on requests under `/payments/{id}`.
 *
 * Plus `User-Agent: GoPay PHP v4 <version>`, so the gateway's log tells this SDK from others.
 *
 * @internal
 */
final class Correlation
{
    public const TRACE_ID = 'X-GoPay-Trace-Id';
    public const TRANSACTION_ID = 'X-GoPay-Transaction-Id';
    public const PAYMENT_SESSION_ID = 'X-GoPay-Payment-Session-Id';

    private const PACKAGE = 'gopaycommunity/gopay-php-api-v4';

    /** What the gateway accepts as a transaction id; anything else would be dropped from its log. */
    private const TRANSACTION_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** Printable ASCII: a header value cannot carry anything else. */
    private const USER_AGENT_SUFFIX_PATTERN = '/^[\x20-\x7E]{1,200}$/D';

    private const PAYMENT_PATH = '#^/?payments/([^/?\#]+)#';

    public readonly string $userAgent;

    private ?string $transactionId = null;

    /**
     * @throws GoPaySdkException InvalidConfig when the suffix is not 1–200 printable ASCII characters
     */
    public function __construct(?string $userAgentSuffix = null)
    {
        $this->userAgent = self::userAgent($userAgentSuffix);
    }

    /**
     * @throws GoPaySdkException InvalidConfig
     */
    public static function userAgent(?string $suffix): string
    {
        $base = 'GoPay PHP v4 ' . self::version();
        if ($suffix === null) {
            return $base;
        }
        $trimmed = trim($suffix);
        if (preg_match(self::USER_AGENT_SUFFIX_PATTERN, $trimmed) !== 1) {
            throw new GoPaySdkException(
                '[GoPaySDK] userAgentSuffix must be 1–200 printable ASCII characters.',
                ErrorCode::InvalidConfig,
            );
        }

        return "{$base} {$trimmed}";
    }

    public static function isValidTransactionId(string $transactionId): bool
    {
        return preg_match(self::TRANSACTION_ID_PATTERN, $transactionId) === 1;
    }

    /**
     * Runs `$call` as one transaction: under `$transactionId` when given, else under the
     * transaction already running (an SDK method called from another, or from inside
     * withTransactionId()), else under a new one.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    public function inTransaction(callable $call, ?string $transactionId = null): mixed
    {
        $outer = $this->transactionId;
        $this->transactionId = $transactionId ?? $outer ?? self::uuid();
        try {
            return $call();
        } finally {
            $this->transactionId = $outer;
        }
    }

    /** The running transaction's id, or a new one for a request made outside any SDK call. */
    public function transactionId(): string
    {
        return $this->transactionId ?? self::uuid();
    }

    /**
     * The request with the SDK's headers set. Set last, so a per-request header cannot replace them.
     */
    public function apply(\Psr\Http\Message\RequestInterface $request, string $path): \Psr\Http\Message\RequestInterface
    {
        $request = $request
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader(self::TRACE_ID, self::uuid())
            ->withHeader(self::TRANSACTION_ID, $this->transactionId());

        // Still percent-encoded as it went into the path: a header value must stay ASCII.
        if (preg_match(self::PAYMENT_PATH, $path, $match) === 1) {
            return $request->withHeader(self::PAYMENT_SESSION_ID, $match[1]);
        }

        return $request->withoutHeader(self::PAYMENT_SESSION_ID);
    }

    /**
     * The ids a request went out with, or null for one these headers were never set on.
     *
     * @return array{string, string}|null [traceId, transactionId]
     */
    public static function idsOf(\Psr\Http\Message\RequestInterface $request): ?array
    {
        $trace = $request->getHeaderLine(self::TRACE_ID);
        $transaction = $request->getHeaderLine(self::TRANSACTION_ID);

        return $trace !== '' && $transaction !== '' ? [$trace, $transaction] : null;
    }

    /** RFC 9562 version 4. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * The installed version of this package, from Composer's own record, so no constant has to
     * be kept in step with the release. `unknown` outside a Composer install.
     */
    private static function version(): string
    {
        try {
            $version = class_exists(InstalledVersions::class)
                ? InstalledVersions::getPrettyVersion(self::PACKAGE)
                : null;
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        return is_string($version) && preg_match('/^[\x21-\x7E]{1,64}$/D', $version) === 1 ? $version : 'unknown';
    }
}

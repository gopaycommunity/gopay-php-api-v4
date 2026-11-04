<?php

declare(strict_types=1);

namespace GoPay\Payments\Exception;

/**
 * The ids of the request an error came from, as GoPay's log records them: quote them to GoPay
 * support and the request can be found.
 */
trait CarriesCorrelation
{
    /**
     * `X-GoPay-Trace-Id` of the HTTP request this error came from — also on a timeout or a
     * network error, where no response arrived. Null when no request went out (an invalid
     * argument, no access token). Set by the SDK; read it, do not write it.
     */
    public ?string $traceId = null;

    /**
     * `X-GoPay-Transaction-Id` of the SDK call this error came from, shared by every request
     * the call made (token refresh, retry, polling). Null as {@see $traceId} is.
     */
    public ?string $transactionId = null;

    /**
     * Records the request the error came from, unless it already carries one: an error raised
     * by an inner request (a token refresh) keeps that request's ids.
     *
     * @internal
     */
    public function attachCorrelation(string $traceId, string $transactionId): void
    {
        if ($this->traceId === null) {
            $this->traceId = $traceId;
            $this->transactionId = $transactionId;
        }
    }
}

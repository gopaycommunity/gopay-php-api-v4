<?php

declare(strict_types=1);

namespace GoPay\Payments\Exception;

enum ErrorCode: string
{
    // Authentication errors
    /** No access token in store — call authenticate() or provide credentials. */
    case AuthTokenMissing = 'AUTH_TOKEN_MISSING';
    /** Re-authentication (client_credentials grant) failed. */
    case AuthRefreshFailed = 'AUTH_REFRESH_FAILED';
    /** Token response from server is missing required fields. */
    case AuthInvalidResponse = 'AUTH_INVALID_RESPONSE';
    /** No client credentials stored — call authenticate() first. */
    case AuthCredentialsMissing = 'AUTH_CREDENTIALS_MISSING';
    /** Request was unauthorized even after a successful token refresh — check OAuth2 scopes. */
    case AuthUnauthorized = 'AUTH_UNAUTHORIZED';

    // Network errors
    /** Network-level failure (no response received), including timeouts. */
    case NetworkError = 'NETWORK_ERROR';
    /**
     * API responded successfully but the body could not be read: not the JSON object or list
     * expected, a field holding a value the SDK's model does not know (an enum state added to the
     * API later, an object where a string belongs), or an events page that would page forever.
     */
    case UnexpectedResponse = 'UNEXPECTED_RESPONSE';

    // Polling errors
    /**
     * Polling gave up: a charge was neither decided nor waiting on the customer's 3DS step,
     * or a refund did not leave REQUESTED, within the timeout. Raised by both awaitChargeState() and
     * awaitRefundState() — the refund poller reuses this case rather than adding its own.
     */
    case ChargeTimeout = 'CHARGE_TIMEOUT';
    /** Charge reached terminal FAILED state. */
    case ChargeFailed = 'CHARGE_FAILED';

    // Webhook errors
    /**
     * The request is not a webhook GoPay signed: a missing or malformed `X-GoPay-Signature`, a key
     * GoPay does not publish, a signature that does not match the body, or a body that is not an
     * event. Answer 4xx; a retry carries the same bytes. Not passed to onError: anyone can send one.
     */
    case WebhookInvalid = 'WEBHOOK_INVALID';
    /**
     * The webhook was not checked; answer 5xx so GoPay delivers it again. Either the signing keys
     * could not be loaded (`GET /webhooks/public-keys` failed, or had no usable key) — the failure
     * is the previous exception, carrying that request's ids — or the webhook names a `kid` the
     * SDK does not have and the keys were fetched less than 10 seconds ago, with no previous
     * exception. Not passed to onError: a made-up `kid` is enough to cause it.
     */
    case WebhookKeysUnavailable = 'WEBHOOK_KEYS_UNAVAILABLE';

    // Usage errors
    /** SDK configuration is invalid (e.g. malformed baseUrl). */
    case InvalidConfig = 'INVALID_CONFIG';
    /** A required argument is missing or invalid. */
    case InvalidArgument = 'INVALID_ARGUMENT';
}

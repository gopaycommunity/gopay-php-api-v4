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
    /** Request timed out. */
    case NetworkTimeout = 'NETWORK_TIMEOUT';
    /** Network-level failure (no response received). */
    case NetworkError = 'NETWORK_ERROR';
    /** API responded successfully but the body shape was unexpected (not a JSON object/array). */
    case UnexpectedResponse = 'UNEXPECTED_RESPONSE';

    // Charge polling errors
    /** Charge did not leave REQUESTED/PROCESSING within the timeout. */
    case ChargeTimeout = 'CHARGE_TIMEOUT';
    /** Charge reached terminal FAILED state. */
    case ChargeFailed = 'CHARGE_FAILED';

    // Usage errors
    /** SDK configuration is invalid (e.g. malformed baseUrl). */
    case InvalidConfig = 'INVALID_CONFIG';
    /** A required argument is missing or invalid. */
    case InvalidArgument = 'INVALID_ARGUMENT';
}

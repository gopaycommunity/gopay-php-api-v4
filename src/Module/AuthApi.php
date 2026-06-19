<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Http\HttpClient;

/**
 * Authentication module — wraps the OAuth2 client_credentials flow.
 *
 * Tokens are stored inside {@see HttpClient} and attached automatically to
 * every subsequent API call. The raw token is intentionally not returned —
 * it must remain server-side and must never be exposed to callers or logged.
 */
final class AuthApi
{
    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Authenticate using the client_credentials grant.
     *
     * Stores the resulting access token internally. All subsequent API calls
     * will attach the Bearer token automatically. Tokens are refreshed
     * transparently before expiry.
     *
     * POST /oauth2/token
     *
     * @throws GoPaySdkException
     */
    public function authenticate(string $clientId, string $clientSecret, string $scope): void
    {
        if ($clientId === '') {
            throw new GoPaySdkException('[GoPaySDK] clientId must not be empty.', ErrorCode::InvalidArgument);
        }
        if ($clientSecret === '') {
            throw new GoPaySdkException('[GoPaySDK] clientSecret must not be empty.', ErrorCode::InvalidArgument);
        }
        if ($scope === '') {
            throw new GoPaySdkException('[GoPaySDK] scope must not be empty.', ErrorCode::InvalidArgument);
        }

        $this->client->authenticate($clientId, $clientSecret, $scope);
    }

    /**
     * Returns true if an access token is currently stored.
     * Does not check expiry — expired tokens are refreshed transparently on the next API call.
     */
    public function isAuthenticated(): bool
    {
        return $this->client->isAuthenticated();
    }

    /**
     * Clear all stored tokens and credentials.
     * After calling this, all API calls will throw until re-authenticated.
     */
    public function logout(): void
    {
        $this->client->logout();
    }

    /**
     * Store the shareable key at runtime (e.g. fetched from an admin API).
     * The shareable key is a public credential safe to expose to the browser.
     */
    public function setShareableKey(string $key): void
    {
        $this->client->setShareableKey($key);
    }

    /**
     * Return the shareable key + client_id needed to bootstrap the browser SDK.
     *
     * Call this on your server and pass the result to the browser page so it
     * can initialise `GoPayBrowserSDK`. Never returns the client_secret.
     *
     * @throws GoPaySdkException
     *
     * @return array{shareable_key: string, client_id: string}
     */
    public function getBrowserKeys(): array
    {
        $shareableKey = $this->client->getShareableKey();
        $clientId = $this->client->getClientId();

        if ($shareableKey === null || $clientId === null) {
            throw new GoPaySdkException(
                '[GoPaySDK] getBrowserKeys() requires shareableKey in Config and a prior authenticate() call.',
                ErrorCode::AuthCredentialsMissing,
            );
        }

        return ['shareable_key' => $shareableKey, 'client_id' => $clientId];
    }
}

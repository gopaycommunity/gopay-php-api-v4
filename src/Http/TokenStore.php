<?php

declare(strict_types=1);

namespace GoPay\Payments\Http;

/**
 * Stores the OAuth2 access token and the client credentials needed to refresh it.
 *
 * All state is in-memory and scoped to a single SDK instance (one web request
 * or CLI process). PHP is single-threaded, so no locking is required.
 */
final class TokenStore
{
    private ?string $accessToken = null;
    private ?int $expiresIn = null;
    private ?int $issuedAt = null;

    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private ?string $scope = null;

    public function setToken(string $accessToken, int $expiresIn): void
    {
        $this->accessToken = $accessToken;
        $this->expiresIn = $expiresIn;
        $this->issuedAt = time();
    }

    public function setClientCredentials(string $clientId, string $clientSecret, string $scope): void
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->scope = $scope;
        // Clear stale token when credentials change.
        $this->clearToken();
    }

    public function clear(): void
    {
        $this->clearToken();
        $this->clientId = null;
        $this->clientSecret = null;
        $this->scope = null;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function hasAccessToken(): bool
    {
        return $this->accessToken !== null;
    }

    /**
     * Returns true if the token expires within $bufferSeconds seconds
     * (i.e. we should proactively refresh before the call fails with 401).
     */
    public function isExpiringSoon(int $bufferSeconds = 30): bool
    {
        if ($this->accessToken === null || $this->expiresIn === null || $this->issuedAt === null) {
            return false;
        }

        $expiresAt = $this->issuedAt + $this->expiresIn;

        return time() >= $expiresAt - $bufferSeconds;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function hasClientCredentials(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null && $this->scope !== null;
    }

    private function clearToken(): void
    {
        $this->accessToken = null;
        $this->expiresIn = null;
        $this->issuedAt = null;
    }
}

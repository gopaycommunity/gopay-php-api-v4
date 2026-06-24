<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\PermanentCardTokenDetails;
use GoPay\Payments\Http\HttpClient;

/**
 * Cards module — card token lifecycle.
 *
 * Card number encryption (raw PAN → JWE) happens inside the GoPay-hosted
 * iframe on the browser side, not in this SDK. This module handles the
 * server-side steps: retrieving, deleting, and tokenizing the encrypted payload
 * returned by the iframe.
 *
 * GET /encryption/public-key and raw JWE construction are intentionally absent.
 */
final class CardsApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Retrieve details of a stored permanent card token.
     * Requires the `card:read` OAuth2 scope.
     *
     * GET /cards/tokens/{card_id}
     *
     * @throws GoPaySdkException
     */
    public function getCardDetails(string $cardId): PermanentCardTokenDetails
    {
        $cid = $this->requireNonEmpty($cardId, 'cardId');

        return $this->client->get("/cards/tokens/{$cid}", PermanentCardTokenDetails::class);
    }

    /**
     * Delete a stored permanent card token.
     * Requires the `card:write` OAuth2 scope.
     *
     * DELETE /cards/tokens/{card_id}
     *
     * @throws GoPaySdkException
     */
    public function deleteCard(string $cardId): void
    {
        $cid = $this->requireNonEmpty($cardId, 'cardId');
        $this->client->delete("/cards/tokens/{$cid}");
    }

    /**
     * Tokenize an encrypted card payload received from the browser.
     *
     * The $payload is the JWE compact serialization string produced by the
     * GoPay-hosted card form iframe (via the `return-payload` submit mode).
     * Forward it from the browser to your server without modification, then
     * pass it here to exchange it for a permanent card token.
     *
     * Requires the `card:write` OAuth2 scope.
     *
     * POST /cards/tokens
     *
     * @throws GoPaySdkException
     */
    public function tokenizeEncryptedCard(string $payload): PermanentCardTokenDetails
    {
        $validPayload = $this->requireNonEmpty($payload, 'payload');

        return $this->client->post('/cards/tokens', ['payload' => $validPayload], PermanentCardTokenDetails::class);
    }
}

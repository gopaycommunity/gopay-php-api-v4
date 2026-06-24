<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Http\HttpClient;

/**
 * Payment Links module — create and manage shareable payment links.
 */
final class LinksApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Create a shareable payment link.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /eshops/{goid}/links
     *
     * @param array<string, mixed> $params Link creation parameters (payment data, expiry, reusability…).
     *
     * @throws GoPaySdkException
     */
    public function createPaymentLink(string $goid, array $params): LinkDetails
    {
        $gid = $this->requireNonEmpty($goid, 'goid');

        return $this->client->post("/eshops/{$gid}/links", $params, LinkDetails::class);
    }

    /**
     * Retrieve the current state of a payment link.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /links/{link_id}
     *
     * @throws GoPaySdkException
     */
    public function linkStatus(string $linkId): LinkDetails
    {
        $lid = $this->requireNonEmpty($linkId, 'linkId');

        return $this->client->get("/links/{$lid}", LinkDetails::class);
    }

    /**
     * Disable a link so it can no longer accept payments.
     * Requires the `payment:write` OAuth2 scope.
     *
     * DELETE /links/{link_id}
     *
     * @throws GoPaySdkException
     */
    public function disableLink(string $linkId): void
    {
        $lid = $this->requireNonEmpty($linkId, 'linkId');
        $this->client->delete("/links/{$lid}");
    }
}

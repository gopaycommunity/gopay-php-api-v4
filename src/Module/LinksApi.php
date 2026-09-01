<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\LinkDetails;
use GoPay\Payments\Http\HttpClient;

/**
 * Payment Links module — create and manage shareable payment links.
 *
 * A link stores the data for a payment without creating one. The payment is
 * created when a customer opens the link's `url`, which redirects them to the
 * gateway. That customer-facing address is served outside this API and needs no
 * authentication — the code it ends in is the only credential — so it is not
 * represented here.
 *
 * All three operations are scoped to the eshop that owns the link. A link
 * belonging to another eshop answers 404 rather than 403, so that the response
 * does not reveal whether it exists.
 */
final class LinksApi
{
    use ValidatesInput;

    public function __construct(
        private readonly HttpClient $client,
    ) {}

    /**
     * Create a payment link.
     * Requires the `payment:write` OAuth2 scope.
     *
     * POST /eshops/{goid}/links
     *
     * @param array<string, mixed> $params Link parameters: `payment` (required), plus the
     *                                     optional `expires_in` in seconds and `reusable`.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function createPaymentLink(string $goid, array $params): LinkDetails
    {
        $gid = $this->requirePathSegment($goid, 'goid');

        return $this->client->post("/eshops/{$gid}/links", $params, LinkDetails::class);
    }

    /**
     * Retrieve the current settings and state of a payment link.
     * Requires the `payment:read` OAuth2 scope.
     *
     * GET /eshops/{goid}/links/{link_id}
     *
     * Expiry is evaluated on read: a link past its `expires_at` reports
     * `active: false` with stop reason `EXPIRED` without anything being written first.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function linkStatus(string $goid, string $linkId): LinkDetails
    {
        $gid = $this->requirePathSegment($goid, 'goid');
        $lid = $this->requirePathSegment($linkId, 'linkId');

        return $this->client->get("/eshops/{$gid}/links/{$lid}", LinkDetails::class);
    }

    /**
     * Disable a link so it can no longer start a new payment.
     * Requires the `payment:write` OAuth2 scope.
     *
     * DELETE /eshops/{goid}/links/{link_id}
     *
     * This is not a delete: the link can still be read back and reports stop reason
     * `FROM_API`. Disabling a link that is already inactive answers 409 — including a
     * one-shot link that has already been used, which stays a pointer to the payment
     * it created. To stop that payment, cancel the payment itself.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function disableLink(string $goid, string $linkId): void
    {
        $gid = $this->requirePathSegment($goid, 'goid');
        $lid = $this->requirePathSegment($linkId, 'linkId');
        $this->client->delete("/eshops/{$gid}/links/{$lid}");
    }
}

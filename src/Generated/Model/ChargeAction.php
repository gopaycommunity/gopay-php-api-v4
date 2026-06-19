<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated\Model;

use GoPay\Payments\Generated\ModelInterface;

/**
 * The action associated with a payment charge (e.g. 3DS redirect).
 *
 * When a charge requires 3DS authentication, the `redirect_url` field holds
 * the URL your server must redirect the customer to.
 */
final class ChargeAction implements ModelInterface
{
    public function __construct(
        /** The URL to redirect the customer to (3DS authentication). Null if no redirect needed. */
        public readonly ?string $redirectUrl = null,
        /** The type of required action, e.g. 'REDIRECT'. */
        public readonly ?string $actionType = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            redirectUrl: isset($data['redirect_url']) && is_string($data['redirect_url']) ? $data['redirect_url'] : null,
            actionType: isset($data['action_type']) && is_string($data['action_type']) ? $data['action_type'] : null,
        );
    }

    /** Returns the 3DS redirect URL if one is required, or null for frictionless charges. */
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}

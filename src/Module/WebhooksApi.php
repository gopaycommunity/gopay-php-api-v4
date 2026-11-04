<?php

declare(strict_types=1);

namespace GoPay\Payments\Module;

use GoPay\Payments\Event\EventFactory;
use GoPay\Payments\Exception\ErrorCode;
use GoPay\Payments\Exception\GoPayHttpException;
use GoPay\Payments\Exception\GoPaySdkException;
use GoPay\Payments\Generated\Model\JWKSet;
use GoPay\Payments\Http\HttpClient;
use GoPay\Payments\Webhook\DetachedJws;
use GoPay\Payments\Webhook\RsaJwk;

/**
 * Webhooks module — checks that a request comes from GoPay and turns it into an event.
 *
 * The signing keys are fetched with the client's own token and kept by `kid` for the life of
 * this client, so a long-running worker calls GoPay for them once an hour, and a PHP-FPM
 * request that builds its client per request once per webhook.
 *
 * @phpstan-import-type GoPayEvent from EventFactory
 */
final class WebhooksApi
{
    /**
     * An unknown `kid` refetches the keys at most this often: anyone can send a request naming a
     * made-up key, and each one must not become a call to GoPay. Short, because a real webhook
     * signed with a newly rotated-in key waits it out as a 5xx that GoPay retries.
     */
    private const REFETCH_INTERVAL_SECONDS = 10.0;

    /** Older keys are refetched before use, so a key GoPay withdraws stops verifying. */
    private const KEYS_MAX_AGE_SECONDS = 3600.0;

    private const PUBLIC_KEYS_PATH = '/webhooks/public-keys';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private array $keys = [];

    private ?float $fetchedAt = null;

    private ?\Throwable $lastFailure = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (callable(): float)|null $clock Seconds, monotonic; tests pass their own.
     */
    public function __construct(
        private readonly HttpClient $client,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Check that a webhook comes from GoPay and return its event.
     *
     * @param string|list<string>|null $signatureHeader
     *
     * @throws GoPaySdkException WebhookInvalid (answer 4xx) or WebhookKeysUnavailable (answer 5xx)
     *
     * @return GoPayEvent
     */
    public function verifyWebhook(string $rawBody, string|array|null $signatureHeader): object
    {
        try {
            $signature = DetachedJws::parse($signatureHeader);
        } catch (\UnexpectedValueException $e) {
            $this->client->emitError(self::invalid($e->getMessage()));
        }

        $key = $this->keyFor($signature->kid);
        if ($key === null) {
            $this->client->emitError(self::invalid('it is signed with a key GoPay does not publish'));
        }
        if (!$signature->verify($key, $rawBody)) {
            $this->client->emitError(self::invalid('the signature does not match the body'));
        }

        try {
            return EventFactory::fromDecoded(json_decode($rawBody));
        } catch (\UnexpectedValueException $e) {
            $this->client->emitError(self::invalid('the body is not an event', $e));
        }
    }

    /**
     * The public keys GoPay signs webhooks with, as a JWK Set.
     *
     * @throws GoPaySdkException
     * @throws GoPayHttpException
     */
    public function getWebhookPublicKeys(): JWKSet
    {
        return $this->client->get(self::PUBLIC_KEYS_PATH, JWKSet::class);
    }

    /**
     * The key, or null when GoPay's current keys do not include it. Throws when that could not
     * be established — not checked is not forged, and GoPay retries only a 5xx.
     */
    private function keyFor(string $kid): ?\OpenSSLAsymmetricKey
    {
        $fetchedAt = $this->fetchedAt;
        $age = $fetchedAt === null ? INF : ($this->clock)() - $fetchedAt;
        $known = isset($this->keys[$kid]);
        $checked = false;
        if ($age >= self::KEYS_MAX_AGE_SECONDS || (!$known && $age >= self::REFETCH_INTERVAL_SECONDS)) {
            $this->load();
            $checked = true;
        }

        $key = $this->keys[$kid] ?? null;
        if ($key !== null) {
            return $key;
        }
        if (!$checked || $this->lastFailure !== null) {
            $this->client->emitError(self::keysUnavailable($this->lastFailure, $checked));
        }

        return null;
    }

    /** Replaces the keys as a whole on success; a failed fetch keeps the last set. */
    private function load(): void
    {
        try {
            $set = $this->client->getObject(self::PUBLIC_KEYS_PATH);
            $next = [];
            foreach (is_array($set->keys ?? null) ? $set->keys : [] as $jwk) {
                if (!$jwk instanceof \stdClass || !is_string($jwk->kid ?? null)) {
                    continue;
                }
                $key = RsaJwk::toPublicKey($jwk);
                if ($key !== null) {
                    $next[$jwk->kid] = $key;
                }
            }
            $this->keys = $next;
            $this->lastFailure = null;
        } catch (GoPaySdkException|GoPayHttpException $e) {
            $this->lastFailure = $e;
        } finally {
            $this->fetchedAt = ($this->clock)();
        }
    }

    // Messages carry only text this SDK wrote: everything in the request came from whoever sent it.
    private static function invalid(string $reason, ?\Throwable $previous = null): GoPaySdkException
    {
        return new GoPaySdkException(
            "[GoPaySDK] Not a valid GoPay webhook: {$reason}.",
            ErrorCode::WebhookInvalid,
            $previous,
        );
    }

    private static function keysUnavailable(?\Throwable $cause, bool $checked): GoPaySdkException
    {
        if (!$checked) {
            return new GoPaySdkException(
                '[GoPaySDK] The webhook names a signing key the SDK has not loaded, and the keys were fetched moments ago, so it was not verified yet.',
                ErrorCode::WebhookKeysUnavailable,
            );
        }
        $hint = $cause instanceof GoPayHttpException && $cause->status === 403
            ? ' The access token needs the shared:read scope.'
            : '';

        return new GoPaySdkException(
            "[GoPaySDK] The webhook signing keys could not be loaded, so the webhook was not verified.{$hint}",
            ErrorCode::WebhookKeysUnavailable,
            $cause,
        );
    }
}

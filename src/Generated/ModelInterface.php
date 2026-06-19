<?php

declare(strict_types=1);

namespace GoPay\Payments\Generated;

/**
 * Contract for all API response model classes.
 *
 * Implementing classes are populated from decoded JSON via {@see fromArray()}.
 * All model classes live in GoPay\Payments\Generated\Model\ and are excluded
 * from PHPStan analysis (see phpstan.neon), but their types ARE known to
 * PHPStan through the class-string<T extends ModelInterface> template pattern
 * used in HttpClient.
 */
interface ModelInterface
{
    /**
     * Hydrate a model from a decoded JSON array.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static;
}

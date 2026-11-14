<?php

namespace Owise\Support;

use Owise\Exceptions\InvalidConfiguration;

/**
 * A Wise global API version verified by this release of Owise.
 */
enum ApiVersion: string
{
    case V2026Q4 = '2026Q4';

    /**
     * Parse the configured Wise API version.
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(mixed $value): self
    {
        if (! is_string($value) || ($version = self::tryFrom($value)) === null) {
            throw new InvalidConfiguration(
                'wise.api_version must be a Wise API version verified by Owise (currently 2026Q4).',
            );
        }

        return $version;
    }
}

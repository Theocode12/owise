<?php

namespace Owise\Auth;

use InvalidArgumentException;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;

/**
 * Resolve a fixed personal API token.
 */
final readonly class StaticTokenProvider implements AccessTokenProviderInterface
{
    /**
     * Create a new static token provider.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private string $token)
    {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException('Wise API token cannot be empty.');
        }
    }

    /**
     * Get the configured API token.
     */
    public function getAccessToken(): string
    {
        return $this->token;
    }
}

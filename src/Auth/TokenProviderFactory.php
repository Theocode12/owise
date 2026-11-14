<?php

namespace Owise\Auth;

use Closure;
use Owise\Exceptions\InvalidConfiguration;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;

/**
 * Resolve token providers for the configured authentication types.
 */
final class TokenProviderFactory
{
    /**
     * The registered factories keyed by authentication type.
     *
     * @var array<string, Closure(array<string, mixed>, string): AccessTokenProviderInterface>
     */
    private array $factories = [];

    /**
     * Register a factory for a custom authentication type.
     *
     * @param  Closure(array<string, mixed>, string): AccessTokenProviderInterface  $factory
     */
    public function extend(
        string $type,
        Closure $factory,
    ): void {
        $this->factories[$type] = $factory;
    }

    /**
     * Make a token provider for the given authentication type.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidConfiguration
     */
    /** @param array<string, mixed> $config */
    public function make(string $type, array $config, string $profileName): AccessTokenProviderInterface
    {
        if ($type === 'personal_token') {
            $token = $config['token'] ?? null;
            if (! is_string($token) || trim($token) === '') {
                throw new InvalidConfiguration("Wise profile '{$profileName}' requires wise.token when wise.auth is personal_token.");
            }

            return new StaticTokenProvider(trim($token));
        }

        if (! isset($this->factories[$type])) {
            throw new InvalidConfiguration(
                "Wise authentication type '{$type}' is not registered for profile '{$profileName}'. Register it with WiseManager::extendAuth().",
            );
        }

        return ($this->factories[$type])($config, $profileName);
    }
}

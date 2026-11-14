<?php

namespace Owise\Support;

/**
 * Builds cache and lock keys for Wise credentials.
 */
final class CacheKey
{
    /**
     * The key prefix used for all entries.
     *
     * @var string
     */
    private const PREFIX = 'wise';

    /**
     * Get the cache key for a client credentials token.
     */
    public static function clientCredentialsToken(string $environment, string $clientId): string
    {
        return implode(':', [
            self::PREFIX,
            'oauth',
            'client-credentials',
            $environment,
            hash('sha256', $clientId),
        ]);
    }

    /**
     * Get the lock key for a client credentials token refresh.
     */
    public static function clientCredentialsLock(string $environment, string $clientId): string
    {
        return self::lock(self::clientCredentialsToken($environment, $clientId));
    }

    /**
     * Get the lock key for an authorization token refresh.
     */
    public static function authorizationRefreshLock(int|string $authorizationId): string
    {
        return implode(':', [self::PREFIX, 'oauth', 'authorization', (string) $authorizationId, 'refresh']);
    }

    /**
     * Get the lock key for the given cache key.
     */
    public static function lock(string $key): string
    {
        return $key.':lock';
    }
}

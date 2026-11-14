<?php

namespace Owise\Auth;

use Closure;
use Illuminate\Cache\CacheManager;
use Owise\Data\OAuthToken;
use Owise\Support\CacheKey;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;

/**
 * Resolve access tokens for the OAuth client credentials grant.
 *
 * Caches the issued token and coordinates issuance under a cache lock.
 */
final class ClientCredentialsTokenProvider implements AccessTokenProviderInterface
{
    /**
     * The last token issued during this process.
     */
    private ?string $lastToken = null;

    /**
     * Create a new client credentials token provider.
     *
     * @param  Closure(): OAuthToken  $issue  Issue a fresh token from the OAuth server.
     * @param  int  $lockSeconds  The issuance-lock TTL and wait, which must outlive an issuance request.
     */
    public function __construct(
        private readonly CacheManager $cache,
        private readonly string $cacheKey,
        private readonly Closure $issue,
        private readonly int $refreshBeforeSeconds = 300,
        private readonly int $lockSeconds = 60,
    ) {}

    /**
     * Get a cached or freshly issued access token.
     */
    public function getAccessToken(): string
    {
        $cached = $this->cache->store()->get($this->cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $this->lastToken = $cached;
        }

        $lock = $this->cache->lock(CacheKey::lock($this->cacheKey), $this->lockSeconds);
        $token = $lock->block($this->lockSeconds, function (): string {
            $cached = $this->cache->store()->get($this->cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $response = ($this->issue)();
            $ttl = max(1, ($response->expiresIn ?? 3600) - max(0, $this->refreshBeforeSeconds));
            $this->cache->store()->put($this->cacheKey, $response->accessToken, $ttl);

            return $response->accessToken;
        });

        return $this->lastToken = $token;
    }

    /**
     * Invalidate the cached token when it was rejected.
     *
     * Only forgets the cache when no token is given or the given token matches the cached one.
     */
    public function invalidate(?string $token = null): void
    {
        if ($token === null || $token === $this->lastToken || $token === $this->cache->store()->get($this->cacheKey)) {
            $this->cache->store()->forget($this->cacheKey);
        }
    }
}

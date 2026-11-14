<?php

namespace Owise\Auth;

use Closure;
use Illuminate\Cache\CacheManager;
use Owise\Enums\WiseAuthorizationStatus;
use Owise\Events\WiseAuthorizationRevoked;
use Owise\Exceptions\ConnectionRevoked;
use Owise\Exceptions\WiseApiError;
use Owise\Models\WiseAuthorization;
use Owise\Support\CacheKey;
use Owise\Support\WiseEventDispatcher;
use Theocode\Wise\Contracts\AccessTokenProviderInterface;

/**
 * Resolve access tokens for the OAuth authorization code grant.
 *
 * Refreshes under a cache lock and marks the authorization revoked on an invalid grant.
 */
final readonly class AuthorizationTokenProvider implements AccessTokenProviderInterface
{
    /**
     * Create a new authorization code grant token provider.
     *
     * @param  WiseAuthorization  $authorization  The stored OAuth authorization the token belongs to.
     * @param  CacheManager  $cache  The cache used to coordinate refresh locking.
     * @param  int  $refreshBeforeSeconds  The seconds before expiry a token is considered stale.
     * @param  Closure(WiseAuthorization): array{access_token: string, refresh_token?: string|null, access_token_expires_at?: \DateTimeInterface|null, refresh_token_expires_at?: \DateTimeInterface|null, status?: WiseAuthorizationStatus, revoked_at?: \DateTimeInterface|null}  $refresh  Refresh the authorization and return the attributes to persist.
     * @param  int  $lockSeconds  The refresh-lock TTL and wait, which must outlive a refresh request.
     */
    public function __construct(
        private WiseAuthorization $authorization,
        private CacheManager $cache,
        private int $refreshBeforeSeconds,
        private Closure $refresh,
        private int $lockSeconds = 60,
    ) {}

    /**
     * Compute the refresh-lock duration that outlives the configured HTTP timeouts.
     *
     * @param  array<string, mixed>  $httpConfig
     */
    public static function lockSecondsFor(array $httpConfig): int
    {
        return max(60, (int) ($httpConfig['timeout'] ?? 30) + (int) ($httpConfig['connect_timeout'] ?? 10) + 20);
    }

    /**
     * Get a usable access token, refreshing it when it is stale.
     *
     *
     * @throws ConnectionRevoked
     */
    public function getAccessToken(): string
    {
        $authorization = $this->authorization->fresh() ?? $this->authorization;
        if (! $authorization->isActive() || $authorization->refresh_token === null) {
            throw new ConnectionRevoked('The Wise authorization is inactive; call Wise::oauth()->recover() explicitly if it was revoked.');
        }

        if ($this->usable($authorization)) {
            return (string) $authorization->access_token;
        }

        $lock = $this->cache->lock(CacheKey::authorizationRefreshLock(WiseAuthorization::ID), $this->lockSeconds);

        return $lock->block($this->lockSeconds, function () use ($authorization): string {
            $current = $this->authorization->fresh() ?? $authorization;
            if ($this->usable($current)) {
                return (string) $current->access_token;
            }

            try {
                $tokens = ($this->refresh)($current);
            } catch (WiseApiError $e) {
                if (! $e->hasErrorCode('invalid_grant')) {
                    throw $e;
                }

                $current->forceFill([
                    'access_token' => null,
                    'refresh_token' => null,
                    'status' => WiseAuthorizationStatus::Revoked,
                    'revoked_at' => now(),
                ])->save();
                app(WiseEventDispatcher::class)->dispatchApplicationEvent(new WiseAuthorizationRevoked($current));
                throw new ConnectionRevoked('The Wise refresh token is no longer valid; call Wise::oauth()->recover() explicitly.', previous: $e);
            }

            $current->forceFill($tokens)->save();

            return (string) $current->access_token;
        });
    }

    /**
     * Determine if the authorization still holds a usable token.
     */
    private function usable(WiseAuthorization $authorization): bool
    {
        return $authorization->isActive()
            && is_string($authorization->access_token)
            && $authorization->access_token !== ''
            && $authorization->access_token_expires_at !== null
            && $authorization->access_token_expires_at->getTimestamp() - now()->getTimestamp() >= $this->refreshBeforeSeconds;
    }
}

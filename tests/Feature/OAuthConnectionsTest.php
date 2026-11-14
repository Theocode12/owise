<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Owise\Auth\AuthorizationTokenProvider;
use Owise\Auth\ClientCredentialsTokenProvider;
use Owise\Data\OAuthToken;
use Owise\Enums\WiseAuthorizationStatus;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\InvalidRegistrationCode;
use Owise\Facades\Wise;
use Owise\Models\WiseAuthorization;
use Owise\Support\CacheKey;

beforeEach(function () {
    config()->set('wise.environment', 'sandbox');
    config()->set('wise.auth', 'oauth');
    config()->set('wise.oauth', [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_before_seconds' => 21600,
        'client_credentials_refresh_before_seconds' => 300,
        'mtls' => [
            'enabled' => true,
            'certificate' => 'client.crt',
            'private_key' => 'client.key',
            'passphrase' => null,
        ],
    ]);
    config()->set('wise.client_key', 'client-key');
    config()->set('wise.profiles', [
        'default' => ['profile_id' => 123],
        'secondary' => ['profile_id' => 456],
    ]);
});

it('shares client credentials tokens through the cache', function () {
    Cache::setDefaultDriver('array');
    $issued = 0;
    $provider = new ClientCredentialsTokenProvider(
        app('cache'),
        CacheKey::clientCredentialsToken('test', 'client'),
        function () use (&$issued): OAuthToken {
            $issued++;

            return OAuthToken::fromArray([
                'access_token' => 'token-'.(string) $issued,
                'expires_in' => 3600,
            ]);
        },
    );

    expect($provider->getAccessToken())->toBe('token-1')
        ->and($provider->getAccessToken())->toBe('token-1')
        ->and($issued)->toBe(1);

    $provider->invalidate('token-1');

    expect($provider->getAccessToken())->toBe('token-2')
        ->and($issued)->toBe(2);
});

it('centralizes OAuth cache and lock key formats', function () {
    $tokenKey = CacheKey::clientCredentialsToken('sandbox', 'client-id');

    expect($tokenKey)->toBe('wise:oauth:client-credentials:sandbox:'.hash('sha256', 'client-id'))
        ->and(CacheKey::clientCredentialsLock('sandbox', 'client-id'))->toBe(CacheKey::lock($tokenKey))
        ->and(CacheKey::authorizationRefreshLock(WiseAuthorization::ID))->toBe('wise:oauth:authorization:1:refresh');
});

it('imports an API-created user only when the caller explicitly supplies its original code', function () {
    Http::fake(['*' => Http::response([
        'access_token' => 'imported-access',
        'refresh_token' => 'imported-refresh',
        'expires_in' => 43200,
        'refresh_token_expires_in' => 100000,
    ])]);

    $authorization = Wise::oauth()->useRegistrationCode('imported@example.com', str_repeat('r', 32));

    expect($authorization->status)->toBe(WiseAuthorizationStatus::Active)
        ->and($authorization->access_token)->toBe('imported-access');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/token')
        && str_contains($request->body(), 'grant_type=registration_code')
        && str_contains($request->body(), 'email=imported%40example.com')
    );
});

it('rejects an invalid imported registration code with a clear exception', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid user credentials.'], 401)]);

    expect(fn () => Wise::oauth()->useRegistrationCode('imported@example.com', str_repeat('r', 32)))
        ->toThrow(InvalidRegistrationCode::class, 'Wise rejected the email and registration code');
    expect(WiseAuthorization::current())->toBeNull();
});

it('requires an explicit recovery call to replace revoked user tokens', function () {
    WiseAuthorization::query()->create([
        'id' => WiseAuthorization::ID,
        'email' => 'wise-user@example.com',
        'registration_code' => str_repeat('r', 64),
        'status' => WiseAuthorizationStatus::Revoked,
        'revoked_at' => now(),
    ]);
    Http::fake(['*' => Http::response([
        'access_token' => 'recovered-access',
        'refresh_token' => 'recovered-refresh',
        'expires_in' => 43200,
        'refresh_token_expires_in' => 100000,
    ])]);

    $authorization = Wise::oauth()->recover();

    expect($authorization->status)->toBe(WiseAuthorizationStatus::Active)
        ->and($authorization->access_token)->toBe('recovered-access')
        ->and($authorization->refresh_token)->toBe('recovered-refresh');
    Http::assertSentCount(1);
});

it('does not provision or bootstrap an authorization while resolving a profile', function () {
    config()->set('wise.auth', 'oauth');

    expect(fn () => Wise::profile())
        ->toThrow(InvalidConfiguration::class, 'No Wise authorization is stored');

    Http::assertNothingSent();
});

it('shares a refreshed user token across configured profiles', function () {
    WiseAuthorization::query()->create([
        'id' => WiseAuthorization::ID,
        'email' => 'wise-user@example.com',
        'registration_code' => str_repeat('r', 64),
        'status' => WiseAuthorizationStatus::Active,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'access_token_expires_at' => now()->subMinute(),
        'refresh_token_expires_at' => now()->addYear(),
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 43200,
                'refresh_token_expires_in' => 100000,
            ]);
        }

        return Http::response([]);
    });

    Wise::profile('default')->profiles()->list();
    Wise::profile('secondary')->profiles()->list();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/token')
        && str_contains($request->body(), 'refresh_token=old-refresh')
    );
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2026Q4/profiles')
        && $request->hasHeader('Authorization', 'Bearer new-access')
    );

    $authorization = WiseAuthorization::current();
    expect($authorization?->refresh_token)->toBe('new-refresh')
        ->and($authorization?->access_token)->toBe('new-access');
});

it('computes a refresh lock that outlives the HTTP timeouts', function () {
    expect(AuthorizationTokenProvider::lockSecondsFor([]))->toBe(60)
        ->and(AuthorizationTokenProvider::lockSecondsFor(['timeout' => 30, 'connect_timeout' => 10]))->toBe(60)
        ->and(AuthorizationTokenProvider::lockSecondsFor(['timeout' => 50, 'connect_timeout' => 10]))->toBe(80);
});

it('stores a default expiry when a refresh omits expires_in', function () {
    WiseAuthorization::query()->create([
        'id' => WiseAuthorization::ID,
        'email' => 'wise-user@example.com',
        'registration_code' => str_repeat('r', 64),
        'status' => WiseAuthorizationStatus::Active,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'access_token_expires_at' => now()->subMinute(),
        'refresh_token_expires_at' => now()->addYear(),
    ]);

    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
            ]);
        }

        return Http::response([]);
    });

    Wise::profile('default')->profiles()->list();

    $expiresAt = WiseAuthorization::current()?->access_token_expires_at;
    $diff = $expiresAt?->getTimestamp() - now()->getTimestamp();

    expect($expiresAt)->not->toBeNull()
        ->and($diff)->toBeGreaterThan(11 * 3600)
        ->and($diff)->toBeLessThan(13 * 3600);
});

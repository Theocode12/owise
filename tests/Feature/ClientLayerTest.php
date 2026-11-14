<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Owise\Account\Environment;
use Owise\Auth\StaticTokenProvider;
use Owise\Exceptions\ConnectionFailed;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\MissingProfile;
use Owise\Facades\Wise;
use Owise\WiseManager;

it('uses the default profile and the library-wide environment and token', function () {
    config()->set('wise.environment', 'sandbox');
    config()->set('wise.auth', 'personal_token');
    config()->set('wise.token', 'default-secret');
    config()->set('wise.profiles.default', ['profile_id' => '12345']);

    Http::fake([
        'api.wise-sandbox.com/2026Q4/profiles' => Http::response([['id' => 12345]], 200),
    ]);

    $profiles = Wise::profiles()->list();

    expect($profiles)->toHaveCount(1)
        ->and(Wise::profile()->profileId())->toBe(12345);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.wise-sandbox.com/2026Q4/profiles'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer default-secret')
        && $request->hasHeader('X-External-Correlation-Id')
    );
});

it('resolves named profiles while sharing one environment and authentication', function () {
    config()->set('wise.environment', 'production');
    config()->set('wise.auth', 'personal_token');
    config()->set('wise.token', 'shared-secret');
    config()->set('wise.profiles', [
        'default' => ['profile_id' => 123],
        'treasury-eu' => ['profile_id' => 456],
    ]);

    Http::fake(['api.wise.com/2026Q4/profiles' => Http::response([], 200)]);

    app(WiseManager::class)->profile('treasury-eu')->profiles()->list();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.wise.com/2026Q4/profiles'
        && $request->hasHeader('Authorization', 'Bearer shared-secret')
    );
});

it('uses configurable API and website URLs for an environment', function () {
    config()->set('wise.urls.sandbox.api_base_url', 'https://wise-api.example.test/');
    config()->set('wise.urls.sandbox.web_base_url', 'https://wise-web.example.test/');
    wiseProfile(['profile_id' => 101]);

    Http::fake(['wise-api.example.test/2026Q4/profiles' => Http::response([], 200)]);

    Wise::profiles()->list();

    expect(Environment::Sandbox->webBaseUrl(config('wise.urls')))->toBe('https://wise-web.example.test')
        ->and(Environment::Sandbox->apiBaseUrl(config('wise.urls'), true))->toBe('https://api-mtls.wise-sandbox.com');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://wise-api.example.test/2026Q4/profiles');
});

it('requires valid URLs in the Wise URL configuration', function () {
    expect(fn () => Environment::Sandbox->apiBaseUrl([]))
        ->toThrow(InvalidConfiguration::class, "Missing Wise URL configuration at 'wise.urls.sandbox.api_base_url'");

    expect(fn () => Environment::Production->webBaseUrl([
        'production' => ['web_base_url' => 'ftp://wise.com'],
    ]))->toThrow(InvalidConfiguration::class, "Invalid Wise URL configured at 'wise.urls.production.web_base_url'");
});

it('allows auth providers to be registered on the manager', function () {
    config()->set('wise.auth', 'custom');
    config()->set('wise.profiles.custom', ['profile_id' => 101]);

    Http::fake(['api.wise-sandbox.com/2026Q4/profiles' => Http::response([], 200)]);

    app(WiseManager::class)->extendAuth(
        'custom',
        fn (array $config, string $name): StaticTokenProvider => new StaticTokenProvider("{$name}-token"),
    );

    app(WiseManager::class)->profile('custom')->profiles()->list();

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer custom-token'));
});

it('reports unknown profiles, missing tokens and invalid library-wide environments clearly', function () {
    expect(fn () => app(WiseManager::class)->profile('missing'))
        ->toThrow(InvalidConfiguration::class, "Wise profile 'missing' is not configured");

    config()->set('wise.auth', 'personal_token');
    config()->set('wise.token', null);
    config()->set('wise.profiles.no-token', ['profile_id' => 1]);
    expect(fn () => app(WiseManager::class)->profile('no-token'))
        ->toThrow(InvalidConfiguration::class, "Wise profile 'no-token' requires wise.token");

    config()->set('wise.token', 'token');
    config()->set('wise.environment', 'staging');
    config()->set('wise.profiles.bad-environment', ['profile_id' => 1]);
    expect(fn () => app(WiseManager::class)->profile('bad-environment'))
        ->toThrow(InvalidConfiguration::class, 'library-wide Wise environment is invalid');
});

it('ignores per-profile global auth settings while retaining library-wide settings', function () {
    config()->set('wise.environment', 'sandbox');
    config()->set('wise.auth', 'personal_token');
    config()->set('wise.token', 'global-token');
    config()->set('wise.profiles.legacy', [
        'profile_id' => 1,
        'environment' => 'production',
        'auth' => 'custom',
        'token' => 'profile-token',
    ]);
    Http::fake(['api.wise-sandbox.com/2026Q4/profiles' => Http::response([], 200)]);

    app(WiseManager::class)->profile('legacy')->profiles()->list();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.wise-sandbox.com/2026Q4/profiles'
        && $request->hasHeader('Authorization', 'Bearer global-token'));
});

it('throws an actionable exception when profile id is not configured', function () {
    wiseProfile(['profile_id' => null]);

    expect(fn () => app(WiseManager::class)->profile()->profileId())
        ->toThrow(MissingProfile::class, 'Set WISE_PROFILE_ID');
});

it('allows Laravel to block unfaked SDK requests', function () {
    wiseProfile();
    Http::preventStrayRequests();

    try {
        Wise::profiles()->list();
        test()->fail('Expected Laravel to prevent the unfaked Wise request.');
    } catch (ConnectionFailed $exception) {
        expect($exception->getPrevious()?->getPrevious())->toBeInstanceOf(StrayRequestException::class);
    }
});

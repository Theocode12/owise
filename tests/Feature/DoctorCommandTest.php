<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('checks every configured Wise profile without displaying credentials', function () {
    config()->set('wise.token', 'doctor-secret-token');
    config()->set('wise.profiles', [
        'default' => ['profile_id' => 101],
        'business' => ['profile_id' => 202],
    ]);
    config()->set('wise.sca.private_key', __DIR__.'/../Fixtures/keys/sca-test-private.pem');
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101' => Http::response(['id' => 101, 'type' => 'PERSONAL']),
        WISE_SANDBOX.'/2026Q4/profiles/202' => Http::response(['id' => 202, 'type' => 'BUSINESS']),
    ]);

    $this->artisan('wise:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('default: Wise reachability')
        ->expectsOutputToContain('business: Wise reachability');

    expect(Artisan::output())->not->toContain('doctor-secret-token');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/profiles/101'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/profiles/202'));
});

it('reports missing credentials as a failure without making a request', function () {
    config()->set('wise.token', null);
    Http::preventStrayRequests();

    $this->artisan('wise:doctor')
        ->assertFailed()
        ->expectsOutputToContain('Set WISE_API_TOKEN');
});

it('reports a missing Wise profile ID without attempting a remote request', function () {
    config()->set('wise.token', 'doctor-secret-token');
    Http::preventStrayRequests();

    $this->artisan('wise:doctor')
        ->assertFailed()
        ->expectsOutputToContain('Set a valid profile_id for this configured Wise profile.');
});

it('reports failed Wise profile requests without printing response details', function () {
    config()->set('wise.token', 'doctor-secret-token');
    config()->set('wise.profiles.default.profile_id', 101);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101' => Http::response(['message' => 'credential was doctor-secret-token'], 403),
    ]);

    $this->artisan('wise:doctor')
        ->assertFailed()
        ->expectsOutputToContain('check network access, credential permissions, and profile ID');

    expect(Artisan::output())->not->toContain('doctor-secret-token');
});

it('reports invalid webhook keys as a failure', function () {
    config()->set('wise.webhooks.public_keys', ['not a PEM key']);
    Http::preventStrayRequests();

    $this->artisan('wise:doctor')
        ->assertFailed()
        ->expectsOutputToContain('not valid PEM public keys');
});

it('warns about sync queue processing in production without failing all diagnostics', function () {
    config()->set('app.env', 'production');
    $this->app['env'] = 'production';
    config()->set('queue.default', 'sync');
    config()->set('wise.token', 'doctor-secret-token');
    config()->set('wise.profiles.default.profile_id', 101);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101' => Http::response(['id' => 101, 'type' => 'PERSONAL']),
    ]);

    $this->artisan('wise:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('The sync queue is configured in production');
});

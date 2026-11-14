<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Owise\Events\TransferActiveCases;
use Owise\Events\TransferPayoutFailure;
use Owise\Events\TransferStateChanged;
use Owise\WiseServiceProvider;

it('registers the service provider', function () {
    expect($this->app->getProviders(WiseServiceProvider::class))->not->toBeEmpty();
});

it('does not register internal webhook processors as application event listeners', function () {
    $events = $this->app['events'];

    expect($events->getListeners(TransferStateChanged::class))
        ->toHaveCount(0)
        ->and($events->getListeners(TransferPayoutFailure::class))
        ->toHaveCount(0)
        ->and($events->getListeners(TransferActiveCases::class))
        ->toHaveCount(0);
});

it('merges the package config', function () {
    expect(config('wise'))->toBeArray()->toHaveKeys([
        'default_profile', 'environment', 'auth', 'token', 'client_key', 'urls', 'profiles', 'models', 'events', 'oauth', 'webhooks', 'sca', 'http', 'logging', 'routes',
    ]);
});

it('registers the publishable config and package migrations', function () {
    expect(ServiceProvider::publishableGroups())->toContain('wise-config')
        ->and(ServiceProvider::pathsToPublish(WiseServiceProvider::class, 'wise-config'))
        ->toContain(config_path('wise.php'));

    expect(Schema::hasTable('wise_authorizations'))->toBeTrue();
});

it('publishes the config file', function () {
    File::delete(config_path('wise.php'));

    $this->artisan('vendor:publish', ['--tag' => 'wise-config'])->assertSuccessful();

    expect(File::exists(config_path('wise.php')))->toBeTrue();

    File::delete(config_path('wise.php'));
});

it('adds the package to the about command', function () {
    Artisan::call('about', ['--only' => 'owise', '--json' => true]);

    $about = json_decode(Artisan::output(), true);

    expect($about)->toHaveKey('owise')
        ->and($about['owise'])->toHaveKeys(['version', 'default_profile'])
        ->and($about['owise']['default_profile'])->toBe('default');
});

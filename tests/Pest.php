<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Owise\Tests\TestCase;
use Owise\WiseManager;
use Owise\WiseProfile;

const WISE_SANDBOX = 'https://api.wise-sandbox.com';

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/**
 * @return array<array-key, mixed>
 */
function wiseFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__."/Fixtures/wise/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wiseProfile(array $overrides = []): WiseProfile
{
    config()->set('wise.environment', 'sandbox');
    config()->set('wise.auth', 'personal_token');
    config()->set('wise.token', 'test-token');
    config()->set('wise.profiles.default', [
        'profile_id' => 101,
        ...$overrides,
    ]);

    app()->forgetInstance(WiseManager::class);

    return app(WiseManager::class)->profile();
}

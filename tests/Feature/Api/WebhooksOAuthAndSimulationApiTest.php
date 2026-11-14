<?php

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Owise\Account\Environment;
use Owise\Api\ApiClientFactory;
use Owise\Exceptions\AuthenticationFailed;
use Owise\Exceptions\SimulationNotAllowed;
use Owise\WiseManager;

it('manages profile webhook subscriptions with the Wise subscription body', function () {
    $id = '72195556-e5cb-495e-a010-b37a4f2a3043';
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/subscriptions' => Http::sequence()
            ->push(wiseFixture('webhook_subscription'))
            ->push(wiseFixture('webhook_subscription_list')),
        WISE_SANDBOX."/2026Q4/profiles/101/subscriptions/{$id}" => Http::sequence()
            ->push(wiseFixture('webhook_subscription'))
            ->push('', 204),
    ]);
    $webhooks = wiseProfile()->webhooks();

    $created = $webhooks->create('Transfers', 'transfers#state-change', 'https://example.com/wise/webhook');
    $list = $webhooks->list();
    $found = $webhooks->find($id);
    $webhooks->delete($id);

    expect($created->id)->toBe($id)
        ->and($created->triggerOn)->toBe('transfers#state-change')
        ->and($created->deliveryVersion)->toBe('4.0.0')
        ->and($list)->not->toBeEmpty()
        ->and($found->deliveryUrl)->toBe('https://your.webhook.url/12345');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
        'name' => 'Transfers',
        'trigger_on' => 'transfers#state-change',
        'delivery' => ['version' => '4.0.0', 'url' => 'https://example.com/wise/webhook'],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), $id));
});

it('manages application webhook subscriptions', function () {
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);
    $webhooks = wiseProfile()->webhooks();

    $webhooks->createForApplication('client key', 'App', 'transfers#state-change', 'https://example.com/hook');
    $webhooks->testForApplication('client key', 'sub-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/applications/client%20key/subscriptions');
    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/applications/client%20key/subscriptions/sub-1/test-notifications');
});

it('requires https webhook delivery urls', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->webhooks()->create('x', 'transfers#state-change', 'http://example.com'))
        ->toThrow(InvalidArgumentException::class, 'HTTPS');
});

it('sends mtls_enabled in the delivery body when requested', function () {
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);
    $webhooks = wiseProfile()->webhooks();

    $webhooks->create('Transfers', 'transfers#state-change', 'https://example.com/wise/webhook', mtlsEnabled: true);
    $webhooks->create('Transfers', 'transfers#state-change', 'https://example.com/wise/webhook', mtlsEnabled: false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
        'name' => 'Transfers',
        'trigger_on' => 'transfers#state-change',
        'delivery' => ['version' => '4.0.0', 'url' => 'https://example.com/wise/webhook', 'mtls_enabled' => true],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
        'name' => 'Transfers',
        'trigger_on' => 'transfers#state-change',
        'delivery' => ['version' => '4.0.0', 'url' => 'https://example.com/wise/webhook', 'mtls_enabled' => false],
    ]);
});

it('parses mtls_enabled from subscription payloads', function () {
    Http::fake(['*' => Http::response([
        'id' => '72195556-e5cb-495e-a010-b37a4f2a3043',
        'name' => 'Webhook Subscription #1',
        'delivery' => [
            'version' => '4.0.0',
            'url' => 'https://your.webhook.url/12345',
            'mtls_enabled' => true,
        ],
        'trigger_on' => 'transfers#state-change',
        'scope' => ['domain' => 'profile', 'id' => '101'],
    ])]);

    $subscription = wiseProfile()->webhooks()->find('72195556-e5cb-495e-a010-b37a4f2a3043');

    expect($subscription->deliveryMtlsEnabled)->toBeTrue();
});

it('maps a missing mtls_enabled to null', function () {
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);

    expect(wiseProfile()->webhooks()->find('any')->deliveryMtlsEnabled)->toBeNull();
});

it('exchanges supported oauth grants with basic auth and never sends the secret in the body', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/oauth/token' => Http::response(wiseFixture('oauth_token'))]);
    $oauth = app(ApiClientFactory::class)->oauth(Environment::Sandbox);

    $registrationCode = str_repeat('r', 32);
    $oauth->clientCredentials('client-id', 'client-secret');
    $oauth->refreshToken('client-id', 'client-secret', 'refresh-me');
    $token = $oauth->registrationCode('client-id', 'client-secret', 'user@example.com', $registrationCode);

    expect($token->accessToken)->toBe('01234567-89ab-cdef-0123-456789abcdef')
        ->and($token->expiresAt?->format(DATE_ATOM))->toBe('2025-04-11T03:43:28+00:00')
        ->and($token->toArray())->not->toHaveKeys(['accessToken', 'refreshToken'])
        ->and(json_encode($token))->not->toContain('01234567-89ab')
        ->and($token->raw['access_token'])->toBe('[REDACTED]');

    $basic = 'Basic '.base64_encode('client-id:client-secret');
    $forms = [];
    Http::assertSent(function (Request $request) use ($basic, &$forms): bool {
        parse_str($request->body(), $form);
        $forms[] = $form;

        return $request->hasHeader('Authorization', $basic)
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && ! str_contains($request->body(), 'client-secret');
    });

    expect($forms)->toBe([
        ['grant_type' => 'client_credentials'],
        ['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-me'],
        ['grant_type' => 'registration_code', 'client_id' => 'client-id', 'email' => 'user@example.com', 'registration_code' => $registrationCode],
    ]);
});

it('maps oauth invalid_grant errors', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token expired'], 401)]);

    try {
        app(ApiClientFactory::class)->oauth(Environment::Sandbox)->refreshToken('id', 'secret', 'stale');
        test()->fail('Expected an authentication failure.');
    } catch (AuthenticationFailed $exception) {
        expect($exception->hasErrorCode('invalid_grant'))->toBeTrue()
            ->and($exception->getMessage())->toBe('Refresh token expired');
    }
});

it('simulates transfer states and balance top-ups in sandbox', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/simulation/transfers/16521632/processing' => Http::response(wiseFixture('simulation_transfer')),
        WISE_SANDBOX.'/2026Q4/simulation/balance/topup' => Http::response(wiseFixture('simulation_topup')),
    ]);

    $transfer = wiseProfile()->simulate()->transferState(16521632, 'processing');
    $topUp = wiseProfile()->simulate()->balanceTopUp(5, Money::of(100, 'EUR'));

    expect($transfer->id)->toBeInt()->and($topUp->state)->toBe('COMPLETED');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === [
        'profileId' => 101, 'balanceId' => 5, 'currency' => 'EUR', 'amount' => 100, 'channel' => 'TRANSFER',
    ]);
});

it('refuses simulation outside sandbox and unknown statuses', function () {
    wiseProfile();
    config()->set('wise.environment', 'production');
    app()->forgetInstance(WiseManager::class);
    Http::preventStrayRequests();

    expect(fn () => app(WiseManager::class)->profile()->simulate()->transferState(1, 'processing'))
        ->toThrow(SimulationNotAllowed::class);

    config()->set('wise.environment', 'sandbox');
    app()->forgetInstance(WiseManager::class);

    expect(fn () => app(WiseManager::class)->profile()->simulate()->transferState(1, 'teleported'))
        ->toThrow(InvalidArgumentException::class);
});

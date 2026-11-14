<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Owise\Auth\StaticTokenProvider;
use Owise\Events\TransferStateChanged;
use Owise\Events\WebhookHandled;
use Owise\Events\WebhookReceived;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Jobs\ProcessWiseWebhook;
use Owise\Tests\Fixtures\ThrowingWebhookHandler;
use Owise\Webhooks\Payload;
use Owise\Webhooks\WebhookProcessor;
use Owise\WiseManager;

function signedWebhookRequest(string $body, string $deliveryId): TestResponse
{
    $privatePem = file_get_contents(dirname(__DIR__).'/Fixtures/keys/sca-test-private.pem');
    $publicPem = file_get_contents(dirname(__DIR__).'/Fixtures/keys/sca-test-public.pem');
    $privateKey = openssl_pkey_get_private($privatePem);
    expect($privateKey)->not->toBeFalse();
    config()->set('wise.webhooks.public_keys', [$publicPem]);

    $signature = '';
    expect(openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256))->toBeTrue();

    return test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_SHA256' => base64_encode($signature),
        'HTTP_X_DELIVERY_ID' => $deliveryId,
    ], $body);
}

function wiseWebhookBody(array $data = [], string $schema = '4.0.0', string $type = 'transfers#state-change'): string
{
    return json_encode([
        'data' => $data,
        'subscription_id' => 'sub-123',
        'event_type' => $type,
        'schema_version' => $schema,
        'sent_at' => '2026-10-27T12:00:00.000Z',
    ], JSON_THROW_ON_ERROR);
}

it('acknowledges a valid signed delivery and dispatches a single job', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Event::fake([WebhookReceived::class]);
    $deliveryId = (string) Str::uuid();
    $body = wiseWebhookBody(['resource' => ['type' => 'transfer', 'id' => 987], 'occurred_at' => '2026-10-27T11:59:59.123Z']);

    $response = signedWebhookRequest($body, $deliveryId);

    $response->assertOk()->assertJson(['received' => true]);
    Queue::assertPushed(ProcessWiseWebhook::class, fn (ProcessWiseWebhook $job): bool => $job->deliveryId === $deliveryId
        && $job->isTest === false
        && $job->payload['event_type'] === 'transfers#state-change');
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->payload->deliveryId === $deliveryId
        && $event->payload->resourceId === '987');
});

it('does not deduplicate repeated delivery ids', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Event::fake([WebhookReceived::class]);
    $deliveryId = (string) Str::uuid();
    $body = wiseWebhookBody(['resource' => ['type' => 'transfer', 'id' => 987]]);

    $first = signedWebhookRequest($body, $deliveryId);
    $second = signedWebhookRequest($body, $deliveryId);

    $first->assertOk();
    $second->assertOk();
    Queue::assertPushed(ProcessWiseWebhook::class, 2);
    Event::assertDispatched(WebhookReceived::class, 2);
});

it('rejects missing and invalid signatures without dispatching a job', function () {
    Queue::fake();
    $body = wiseWebhookBody();
    $deliveryId = (string) Str::uuid();

    test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DELIVERY_ID' => $deliveryId,
    ], $body)->assertStatus(400);

    config()->set('wise.webhooks.public_keys', ['not a PEM key']);
    test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_SHA256' => base64_encode('invalid-signature'),
        'HTTP_X_DELIVERY_ID' => $deliveryId,
    ], $body)->assertStatus(400);

    Queue::assertNothingPushed();
});

it('allows bypass only outside production when verification is disabled', function () {
    Queue::fake();
    config()->set('wise.webhooks.verify', false);
    $body = wiseWebhookBody();

    test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DELIVERY_ID' => (string) Str::uuid(),
    ], $body)->assertOk();
    Queue::assertPushed(ProcessWiseWebhook::class, 1);

    app()->detectEnvironment(static fn (): string => 'production');
    test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DELIVERY_ID' => (string) Str::uuid(),
    ], $body)->assertStatus(400);
    Queue::assertPushed(ProcessWiseWebhook::class, 1);
});

it('processes a bypassed HTTP delivery through the queued job to a typed event', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Event::fake([WebhookReceived::class, TransferStateChanged::class, WebhookHandled::class]);
    config()->set('wise.webhooks.verify', false);

    $body = wiseWebhookBody([
        'resource' => ['type' => 'transfer', 'id' => 987],
        'previous_state' => 'processing',
        'current_state' => 'outgoing_payment_sent',
        'occurred_at' => '2026-10-27T11:59:59.123Z',
    ]);
    $deliveryId = (string) Str::uuid();

    test()->call('POST', '/wise/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DELIVERY_ID' => $deliveryId,
    ], $body)->assertOk();

    Event::assertDispatched(WebhookReceived::class, 1);
    Queue::assertPushed(ProcessWiseWebhook::class, 1);

    $job = Queue::pushed(ProcessWiseWebhook::class)->sole();
    $job->handle(app(WebhookProcessor::class));

    Event::assertDispatched(TransferStateChanged::class, fn (TransferStateChanged $event): bool => $event->previous === 'processing'
        && $event->current === 'outgoing_payment_sent'
        && $event->transferId === '987'
        && $event->occurredAt?->format('Y-m-d\TH:i:s.v\Z') === '2026-10-27T11:59:59.123Z'
    );
    Event::assertDispatched(WebhookHandled::class, 1);
});

it('normalizes Wise payload timestamps from schema 2 and 4', function () {
    $schemaTwo = Payload::fromArray([
        'schema_version' => '2.0.0',
        'data' => ['resource' => ['type' => 'transfer', 'id' => 41, 'occurred_at' => '2024-06-15T10:30:00Z']],
    ]);
    $schemaFour = Payload::fromArray([
        'schema_version' => '4.0.0',
        'data' => ['resource' => ['type' => 'transfer', 'id' => 42], 'occurred_at' => '2024-06-15T10:30:00.123Z'],
    ]);

    expect($schemaTwo->resourceId)->toBe('41')
        ->and($schemaTwo->occurredAt?->format('Y-m-d\TH:i:s.v\Z'))->toBe('2024-06-15T10:30:00.000Z')
        ->and($schemaFour->resourceId)->toBe('42')
        ->and($schemaFour->occurredAt?->format('Y-m-d\TH:i:s.v\Z'))->toBe('2024-06-15T10:30:00.123Z');
});

it('dispatches a typed domain event and WebhookHandled for known event types', function () {
    Event::fake([TransferStateChanged::class, WebhookHandled::class]);
    $payload = Payload::fromRequest([
        'event_type' => 'transfers#state-change',
        'schema_version' => '4.0.0',
        'data' => [
            'resource' => ['type' => 'transfer', 'id' => 53],
            'previous_state' => 'processing',
            'current_state' => 'outgoing_payment_sent',
            'occurred_at' => '2026-10-27T12:00:00.123Z',
        ],
    ], null, false);

    app(WebhookProcessor::class)->process($payload);

    Event::assertDispatched(TransferStateChanged::class, fn (TransferStateChanged $domainEvent): bool => $domainEvent->previous === 'processing'
        && $domainEvent->current === 'outgoing_payment_sent'
        && $domainEvent->transferId === '53'
        && $domainEvent->occurredAt?->format('Y-m-d\TH:i:s.v\Z') === '2026-10-27T12:00:00.123Z');
    Event::assertDispatched(WebhookHandled::class);
});

it('dispatches WebhookHandled only for test notifications', function () {
    Event::fake([TransferStateChanged::class, WebhookHandled::class]);
    $payload = Payload::fromRequest([
        'event_type' => 'transfers#state-change',
        'schema_version' => '4.0.0',
        'data' => ['resource' => ['type' => 'transfer', 'id' => 53]],
    ], null, true);

    app(WebhookProcessor::class)->process($payload);

    Event::assertDispatched(WebhookHandled::class);
    Event::assertNotDispatched(TransferStateChanged::class);
});

it('dispatches WebhookHandled only for unknown event types', function () {
    Event::fake([TransferStateChanged::class, WebhookHandled::class]);
    $payload = Payload::fromRequest([
        'event_type' => 'unknown#event',
        'schema_version' => '4.0.0',
        'data' => [],
    ], null, false);

    app(WebhookProcessor::class)->process($payload);

    Event::assertDispatched(WebhookHandled::class);
    Event::assertNotDispatched(TransferStateChanged::class);
});

it('lets handler exceptions bubble and keeps the job retryable', function () {
    config()->set('wise.webhooks.handlers.temporary#failure', ThrowingWebhookHandler::class);
    $payload = Payload::fromRequest([
        'event_type' => 'temporary#failure',
        'schema_version' => '4.0.0',
        'data' => [],
    ], null, false);

    expect(fn () => app(WebhookProcessor::class)->process($payload))
        ->toThrow(RuntimeException::class, 'Temporary handler failure.');

    $job = new ProcessWiseWebhook([
        'event_type' => 'temporary#failure',
        'schema_version' => '4.0.0',
        'data' => [],
    ], null, false);

    expect(fn () => $job->handle(app(WebhookProcessor::class)))
        ->toThrow(RuntimeException::class, 'Temporary handler failure.');

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 60, 120, 300]);
});

it('creates configured event subscriptions through the artisan command', function () {
    wiseProfile();
    config()->set('wise.webhooks.events', ['transfers#state-change']);
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);

    test()->artisan('wise:webhooks', [
        '--create' => true,
        '--url' => 'https://example.test/wise/webhook',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->data() === [
            'name' => 'Owise transfers state-change',
            'trigger_on' => 'transfers#state-change',
            'delivery' => ['version' => '4.0.0', 'url' => 'https://example.test/wise/webhook'],
        ]);
});

it('uses a dedicated client-credentials token for application subscriptions with oauth profile auth', function () {
    config()->set('wise.auth', 'oauth');
    config()->set('wise.oauth.client_id', 'partner-client');
    config()->set('wise.oauth.client_secret', 'partner-secret');
    config()->set('wise.oauth.mtls', [
        'enabled' => true,
        'certificate' => 'client.crt',
        'private_key' => 'client.key',
    ]);
    config()->set('wise.client_key', 'application-key');
    config()->set('wise.webhooks.events', ['transfers#state-change']);
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/oauth/token')) {
            parse_str($request->body(), $form);

            return Http::response(($form['grant_type'] ?? null) === 'client_credentials'
                ? ['access_token' => 'application-token', 'expires_in' => 3600]
                : ['error' => 'unexpected_grant'], 200);
        }

        return Http::response(wiseFixture('webhook_subscription'));
    });

    test()->artisan('wise:webhooks', [
        '--application' => true,
        '--create' => true,
        '--url' => 'https://example.test/wise/webhook',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/token')
        && str_contains($request->body(), 'grant_type=client_credentials')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('partner-client:partner-secret'))
        && ! str_contains($request->body(), 'partner-secret'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2026Q4/applications/application-key/subscriptions')
        && $request->hasHeader('Authorization', 'Bearer application-token')
        && $request->data()['trigger_on'] === 'transfers#state-change');
    Http::assertSentCount(2);
});

it('does not resolve custom profile auth for application-level webhook operations', function () {
    config()->set('wise.auth', 'custom');
    config()->set('wise.oauth.client_id', 'partner-client');
    config()->set('wise.oauth.client_secret', 'partner-secret');
    config()->set('wise.oauth.mtls', [
        'enabled' => true,
        'certificate' => 'client.crt',
        'private_key' => 'client.key',
    ]);
    config()->set('wise.client_key', 'application-key');
    $customProviderCreated = false;
    app(WiseManager::class)->extendAuth('custom', function (array $config, string $name) use (&$customProviderCreated): StaticTokenProvider {
        $customProviderCreated = true;

        return new StaticTokenProvider('profile-token');
    });
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'application-token', 'expires_in' => 3600]);
        }

        return Http::response([]);
    });

    test()->artisan('wise:webhooks', ['--application' => true])->assertExitCode(0);

    expect($customProviderCreated)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2026Q4/applications/application-key/subscriptions')
        && $request->hasHeader('Authorization', 'Bearer application-token'));
});

it('requires the root client key and partner client credentials for application subscriptions', function () {
    config()->set('wise.auth', 'oauth');
    config()->set('wise.client_key', null);
    Http::preventStrayRequests();

    test()->artisan('wise:webhooks', ['--application' => true])
        ->expectsOutput('Application subscriptions require a client key. Configure wise.client_key.')
        ->assertExitCode(1);

    Http::assertNothingSent();

    config()->set('wise.client_key', 'application-key');
    config()->set('wise.oauth.client_id', null);
    config()->set('wise.oauth.mtls', [
        'enabled' => true,
        'certificate' => 'client.crt',
        'private_key' => 'client.key',
    ]);
    expect(fn () => app(WiseManager::class)->applicationWebhooks())
        ->toThrow(InvalidConfiguration::class, 'wise.oauth.client_id must be configured.');

    config()->set('wise.oauth.client_id', 'partner-client');
    config()->set('wise.oauth.client_secret', null);
    expect(fn () => app(WiseManager::class)->applicationWebhooks())
        ->toThrow(InvalidConfiguration::class, 'wise.oauth.client_secret must be configured.');

    config()->set('wise.oauth.client_secret', 'partner-secret');
    config()->set('wise.oauth.mtls.enabled', false);
    expect(fn () => app(WiseManager::class)->applicationWebhooks())
        ->toThrow(InvalidConfiguration::class, 'Wise OAuth requires wise.oauth.mtls.enabled');

    Http::assertNothingSent();
});

it('sends mtls_enabled when wise.webhooks.mtls_enabled is true', function () {
    wiseProfile();
    config()->set('wise.webhooks.events', ['transfers#state-change']);
    config()->set('wise.webhooks.mtls_enabled', true);
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);

    test()->artisan('wise:webhooks', [
        '--create' => true,
        '--url' => 'https://example.test/wise/webhook',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === [
            'name' => 'Owise transfers state-change',
            'trigger_on' => 'transfers#state-change',
            'delivery' => ['version' => '4.0.0', 'url' => 'https://example.test/wise/webhook', 'mtls_enabled' => true],
        ]);
});

it('lets --mtls override and --no-mtls disable mTLS delivery', function () {
    wiseProfile();
    config()->set('wise.webhooks.events', ['transfers#state-change']);
    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);

    config()->set('wise.webhooks.mtls_enabled', false);
    test()->artisan('wise:webhooks', [
        '--create' => true,
        '--url' => 'https://example.test/wise/webhook',
        '--mtls' => true,
    ])->assertExitCode(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['delivery']['mtls_enabled'] === true);

    Http::fake(['*' => Http::response(wiseFixture('webhook_subscription'))]);
    config()->set('wise.webhooks.mtls_enabled', true);
    test()->artisan('wise:webhooks', [
        '--create' => true,
        '--url' => 'https://example.test/wise/webhook',
        '--no-mtls' => true,
    ])->assertExitCode(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['delivery']['mtls_enabled'] === false);
});

<?php

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Owise\Jobs\ProcessWiseWebhook;
use Owise\Testing\InteractsWithWiseWebhooks;
use Owise\Testing\WebhookFactory;

uses(InteractsWithWiseWebhooks::class);

beforeEach(function () {
    wiseProfile();
});

it('builds schema 4.0.0 transfer state change payloads', function () {
    $payload = WebhookFactory::transferStateChanged(42, 'processing', 'outgoing_payment_sent', '2026-10-27T11:59:59.123Z', 101);

    expect($payload['event_type'])->toBe('transfers#state-change')
        ->and($payload['schema_version'])->toBe('4.0.0')
        ->and($payload['data']['resource']['id'])->toBe('42')
        ->and($payload['data']['previous_state'])->toBe('processing')
        ->and($payload['data']['current_state'])->toBe('outgoing_payment_sent')
        ->and($payload['data']['occurred_at'])->toBe('2026-10-27T11:59:59.123Z')
        ->and($payload['data']['profile_id'])->toBe(101);
});

it('normalizes DateTime webhook timestamps to UTC without changing the instant', function () {
    $occurredAt = new DateTimeImmutable('2026-10-27T13:59:59.123+02:00');
    $payload = WebhookFactory::transferStateChanged(42, 'processing', 'outgoing_payment_sent', $occurredAt);

    expect($payload['data']['occurred_at'])->toBe('2026-10-27T11:59:59.123Z');
});

it('uses UTC for default webhook timestamps regardless of the app timezone', function () {
    config()->set('app.timezone', 'Pacific/Auckland');

    $payload = WebhookFactory::transferStateChanged(42, 'processing', 'outgoing_payment_sent');
    $expected = now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');

    expect($payload['data']['occurred_at'])->toBe($expected);
});

it('builds balance payloads', function () {
    $payload = WebhookFactory::balanceCredited(64, 'EUR', 9.94, 16.01, '2026-10-27T11:59:59.123Z', 101);

    expect($payload['event_type'])->toBe('balances#credit')
        ->and($payload['data']['resource']['id'])->toBe('64')
        ->and($payload['data']['amount'])->toBe(9.94)
        ->and($payload['data']['post_transaction_balance_amount'])->toBe(16.01);
});

it('signs and posts a webhook through the real verification middleware', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $payload = WebhookFactory::transferStateChanged(16521632, 'processing', 'outgoing_payment_sent');

    $this->postWiseWebhook($payload)
        ->assertOk()
        ->assertJson(['received' => true]);

    Queue::assertPushed(ProcessWiseWebhook::class, fn (ProcessWiseWebhook $job): bool => $job->payload['event_type'] === 'transfers#state-change');
});

it('rejects an unsigned webhook delivery', function () {
    Queue::fake();

    $this->post('/wise/webhook', WebhookFactory::transferStateChanged(1, 'a', 'b'), [
        'Content-Type' => 'application/json',
    ])->assertStatus(400);

    Queue::assertNothingPushed();
});

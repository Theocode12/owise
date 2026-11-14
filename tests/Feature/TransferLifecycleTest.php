<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Enums\WiseTransferStatus;
use Owise\Events\TransferActiveCases;
use Owise\Events\TransferCompleted;
use Owise\Events\TransferFailed;
use Owise\Events\TransferNeedsAttention;
use Owise\Events\TransferPayoutFailure;
use Owise\Events\TransferReturned;
use Owise\Events\TransferStateChanged;
use Owise\Events\TransferStatusUpdated;
use Owise\Exceptions\ConnectionFailed;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Jobs\SendPayout;
use Owise\Models\WiseTransfer;
use Owise\Webhooks\Payload;
use Owise\Webhooks\WebhookProcessor;

beforeEach(function () {
    wiseProfile(['funding' => 'api']);
});

function makeTransferRow(array $overrides = []): WiseTransfer
{
    return WiseTransfer::factory()->created()->create(array_merge([
        'profile_id' => 101,
        'account' => 'default',
        'customer_transaction_id' => (string) Str::uuid(),
        'wise_transfer_id' => 16521632,
        'status' => TransferStatus::Created,
        'wise_status' => 'incoming_payment_waiting',
        'funding_mode' => FundingMode::Api,
        'source_currency' => 'GBP',
        'source_amount' => 10000,
        'target_currency' => 'EUR',
        'target_amount' => 8550,
        'reference' => 'Invoice 42',
    ], $overrides));
}

function processWebhook(array $data, string $type = 'transfers#state-change'): void
{
    $payload = Payload::fromRequest([
        'event_type' => $type,
        'schema_version' => '4.0.0',
        'data' => $data,
    ], null, false);

    app(WebhookProcessor::class)->process($payload);
}

function stateChange(string $current, string $occurredAt, string $transferId = '16521632'): array
{
    return [
        'resource' => ['type' => 'transfer', 'id' => $transferId],
        'previous_state' => 'processing',
        'current_state' => $current,
        'occurred_at' => $occurredAt,
    ];
}

it('updates the mirror and dispatches TransferCompleted on outgoing_payment_sent', function () {
    Event::fake([TransferStatusUpdated::class, TransferCompleted::class]);
    $transfer = makeTransferRow();

    processWebhook(stateChange('outgoing_payment_sent', '2026-11-10T12:00:00.123Z'));

    $transfer->refresh();
    expect($transfer->wise_status)->toBe('outgoing_payment_sent')
        ->and($transfer->isCompleted())->toBeTrue()
        ->and($transfer->funded_at?->format('Y-m-d H:i:s.v'))->toBe('2026-11-10 12:00:00.123')
        ->and($transfer->wise_status_occurred_at?->format('Y-m-d H:i:s.v'))->toBe('2026-11-10 12:00:00.123');

    Event::assertDispatched(TransferStatusUpdated::class);
    Event::assertDispatched(TransferCompleted::class);
});

it('ignores an out-of-order webhook and reconciles from Wise', function () {
    Event::fake([TransferStatusUpdated::class, TransferCompleted::class]);
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response([
        'id' => 16521632, 'status' => 'processing', 'sourceCurrency' => 'GBP', 'sourceValue' => 100, 'targetCurrency' => 'EUR', 'targetValue' => 85.5,
    ])]);
    $transfer = makeTransferRow(['wise_status' => 'processing', 'wise_status_occurred_at' => '2026-11-10 12:00:00.000']);

    processWebhook(stateChange('outgoing_payment_sent', '2026-11-10T11:00:00.000Z'));

    $transfer->refresh();
    expect($transfer->wise_status)->toBe('processing');

    Event::assertNotDispatched(TransferCompleted::class);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers/16521632');
});

it('reconciles from Wise when the webhook timestamp is missing', function () {
    Event::fake([TransferStatusUpdated::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response([
            'id' => 16521632, 'status' => 'funds_converted', 'sourceCurrency' => 'GBP', 'sourceValue' => 100, 'targetCurrency' => 'EUR', 'targetValue' => 85.5,
        ]),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([[
            'id' => 987654321,
            'method' => 'BALANCE',
            'timeCreated' => '2026-11-10T11:59:00.000Z',
        ]]),
    ]);
    $transfer = makeTransferRow();

    processWebhook(['resource' => ['type' => 'transfer', 'id' => '16521632'], 'current_state' => 'funds_converted']);

    expect($transfer->fresh()->wise_status)->toBe('funds_converted')
        ->and($transfer->fresh()->funded_at?->format('Y-m-d H:i:s'))->toBe('2026-11-10 11:59:00');
    Event::assertDispatched(TransferStatusUpdated::class);
});

it('does not mark incoming_payment_initiated as funded because funds have not arrived yet', function () {
    $transfer = makeTransferRow(['wise_status' => 'incoming_payment_waiting']);

    processWebhook(stateChange('incoming_payment_initiated', '2026-11-10T12:00:00.000Z'));

    expect($transfer->fresh()->hasBeenFunded())->toBeFalse();
});

it('retains unknown Wise statuses without enum-cast failures', function () {
    $transfer = makeTransferRow();

    $transfer->applyRemoteStatus('new_wise_state', now()->addSecond(), dispatchEvents: false);

    expect($transfer->fresh()->wise_status)->toBe('new_wise_state')
        ->and(WiseTransferStatus::tryFrom('new_wise_state'))->toBeNull();
});

it('can apply a status silently without dispatching lifecycle events', function () {
    Event::fake([TransferStatusUpdated::class, TransferCompleted::class]);
    $transfer = makeTransferRow();

    $transfer->applyRemoteStatus('outgoing_payment_sent', now()->addSecond(), dispatchEvents: false);

    expect($transfer->fresh()->wise_status)->toBe('outgoing_payment_sent');
    Event::assertNotDispatched(TransferStatusUpdated::class);
    Event::assertNotDispatched(TransferCompleted::class);
});

it('suppresses application events globally without disabling internal webhook processing', function () {
    config()->set('wise.events.application.enabled', false);
    Event::fake([
        TransferStateChanged::class,
        TransferPayoutFailure::class,
        TransferActiveCases::class,
        TransferStatusUpdated::class,
        TransferCompleted::class,
        TransferNeedsAttention::class,
    ]);
    $transfer = makeTransferRow();

    processWebhook(stateChange('outgoing_payment_sent', '2026-11-10T12:00:00.123Z'));
    processWebhook([
        'resource' => ['type' => 'transfer', 'id' => '16521632'],
        'active_cases' => [['type' => 'aml', 'description' => 'needs review']],
    ], 'transfers#active-cases');
    processWebhook([
        'resource' => ['type' => 'transfer', 'id' => '16521632'],
        'failure_reason' => 'invalid recipient details',
    ], 'transfers#payout-failure');

    $transfer->refresh();
    expect($transfer->wise_status)->toBe('outgoing_payment_sent')
        ->and($transfer->funded_at?->format('Y-m-d H:i:s.v'))->toBe('2026-11-10 12:00:00.123')
        ->and($transfer->failure_reason)->toBe('invalid recipient details')
        ->and($transfer->meta['active_cases'])->toBe([['type' => 'aml', 'description' => 'needs review']]);

    Event::assertNotDispatched(TransferStateChanged::class);
    Event::assertNotDispatched(TransferPayoutFailure::class);
    Event::assertNotDispatched(TransferActiveCases::class);
    Event::assertNotDispatched(TransferStatusUpdated::class);
    Event::assertNotDispatched(TransferCompleted::class);
    Event::assertNotDispatched(TransferNeedsAttention::class);
});

it('treats bounced_back as non-terminal and allows the documented rollback', function () {
    Event::fake([TransferStatusUpdated::class, TransferReturned::class, TransferFailed::class, TransferCompleted::class]);
    $transfer = makeTransferRow(['wise_status' => 'outgoing_payment_sent']);

    processWebhook(stateChange('bounced_back', '2026-11-10T12:00:00.000Z'));

    $transfer->refresh();
    expect($transfer->isReturned())->toBeTrue()
        ->and($transfer->isFailed())->toBeFalse();
    Event::assertDispatched(TransferReturned::class);
    Event::assertNotDispatched(TransferFailed::class);

    processWebhook(stateChange('processing', '2026-11-10T13:00:00.000Z'));

    expect($transfer->fresh()->wise_status)->toBe('processing');
    Event::assertNotDispatched(TransferFailed::class);

    processWebhook(stateChange('funds_refunded', '2026-11-10T14:00:00.000Z'));

    expect($transfer->fresh()->isFailed())->toBeTrue();
    Event::assertDispatched(TransferFailed::class);
});

it('flags active cases and dispatches TransferNeedsAttention', function () {
    Event::fake([TransferNeedsAttention::class]);
    $transfer = makeTransferRow();

    processWebhook([
        'resource' => ['type' => 'transfer', 'id' => '16521632'],
        'active_cases' => [['type' => 'aml', 'description' => 'needs review']],
    ], 'transfers#active-cases');

    $transfer->refresh();
    expect($transfer->meta['active_cases'])->toBe([['type' => 'aml', 'description' => 'needs review']]);
    Event::assertDispatched(TransferNeedsAttention::class);
});

it('records a payout failure without changing the transfer status', function () {
    $transfer = makeTransferRow(['wise_status' => 'processing']);

    processWebhook([
        'resource' => ['type' => 'transfer', 'id' => '16521632'],
        'failure_reason' => 'recipient account details incorrect',
    ], 'transfers#payout-failure');

    $transfer->refresh();
    expect($transfer->failure_reason)->toBe('recipient account details incorrect')
        ->and($transfer->wise_status)->toBe('processing');
});

it('cancels a transfer that is still awaiting funding', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/16521632/cancel' => Http::response([
        'id' => 16521632, 'status' => 'cancelled', 'sourceCurrency' => 'GBP', 'sourceValue' => 100, 'targetCurrency' => 'EUR', 'targetValue' => 85.5,
    ])]);
    $transfer = makeTransferRow(['status' => TransferStatus::AwaitingFunding]);

    $transfer->cancel();

    expect($transfer->fresh()->wise_status)->toBe('cancelled')
        ->and($transfer->fresh()->isFailed())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers/16521632/cancel');
});

it('reconciles recently sent transfers to recover late bounce-backs', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response([
        'id' => 16521632, 'status' => 'bounced_back', 'sourceCurrency' => 'GBP', 'sourceValue' => 100, 'targetCurrency' => 'EUR', 'targetValue' => 85.5,
    ])]);
    $transfer = makeTransferRow(['wise_status' => 'outgoing_payment_sent', 'wise_status_occurred_at' => now()]);

    test()->artisan('wise:transfers:sync')->assertExitCode(0);

    expect($transfer->fresh()->wise_status)->toBe('bounced_back');
});

function transferStateJson(string $status = 'incoming_payment_waiting'): array
{
    return [
        'id' => 16521632,
        'status' => $status,
        'sourceCurrency' => 'GBP',
        'sourceValue' => 100,
        'targetCurrency' => 'EUR',
        'targetValue' => 85.5,
        'rate' => 0.855,
        'reference' => 'Invoice 42',
        'quoteUuid' => 'quote-111',
        'targetAccount' => 40000000,
    ];
}

function makeQuotedTransfer(string $account, string $key, ?DateTimeInterface $updatedAt = null): WiseTransfer
{
    return WiseTransfer::factory()->quoted()->create([
        'profile_id' => 101,
        'account' => $account,
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => $key,
        'wise_quote_id' => 'quote-111',
        'wise_recipient_id' => 40000000,
        'status' => TransferStatus::Quoted,
        'funding_mode' => FundingMode::Manual,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'source_amount' => 10000,
        'target_amount' => 8550,
        'reference' => 'Invoice 42',
        'updated_at' => $updatedAt ?? now(),
    ]);
}

class SyncThrowingTransfer extends WiseTransfer
{
    protected $table = 'wise_transfers';

    public function resume(): WiseTransfer
    {
        throw new WiseException('custom resume failed');
    }
}

it('never lets a stale observer overwrite a newer status', function () {
    Event::fake([TransferStatusUpdated::class, TransferCompleted::class]);
    $row = makeTransferRow();

    $copyA = WiseTransfer::query()->find($row->getKey());
    $copyB = WiseTransfer::query()->find($row->getKey());

    $copyA->applyRemoteStatus('outgoing_payment_sent', CarbonImmutable::parse('2026-11-10T12:00:00.000Z'));
    $copyB->applyRemoteStatus('processing', CarbonImmutable::parse('2026-11-10T11:00:00.000Z'));

    expect(WiseTransfer::query()->find($row->getKey())->wise_status)->toBe('outgoing_payment_sent')
        ->and($copyB->wise_status)->toBe('outgoing_payment_sent');

    Event::assertDispatchedTimes(TransferStatusUpdated::class, 1);
    Event::assertDispatchedTimes(TransferCompleted::class, 1);
});

it('propagates a non-404 error when reconciling a transfer without a timestamp', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(['errors' => [['code' => 'internal', 'message' => 'boom']]], 503)]);
    $transfer = makeTransferRow();

    expect(fn () => processWebhook(['resource' => ['type' => 'transfer', 'id' => '16521632'], 'current_state' => 'funds_converted']))
        ->toThrow(WiseApiError::class);

    expect($transfer->fresh()->wise_status)->toBe('incoming_payment_waiting');
});

it('treats a 404 when reconciling a transfer without a timestamp as missing', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response('', 404)]);
    $transfer = makeTransferRow();

    processWebhook(['resource' => ['type' => 'transfer', 'id' => '16521632'], 'current_state' => 'funds_converted']);

    expect($transfer->fresh()->wise_status)->toBe('incoming_payment_waiting');
});

it('propagates a 500 when importing an unknown transfer', function () {
    config()->set('wise.payouts.import_unknown', true);
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers/99999999' => Http::response(['errors' => [['code' => 'internal', 'message' => 'boom']]], 500)]);

    expect(fn () => processWebhook(['resource' => ['type' => 'transfer', 'id' => '99999999'], 'current_state' => 'funds_converted']))
        ->toThrow(WiseApiError::class);
});

it('retryFunding funds when payments are empty and Wise is still waiting', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(transferStateJson()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(['type' => 'BALANCE', 'status' => 'COMPLETED', 'balanceTransactionId' => 987654321]),
    ]);
    $transfer = makeTransferRow(['funding_status' => FundingStatus::Unknown]);

    $transfer->retryFunding();

    expect($transfer->fresh()->funding_status)->toBe(FundingStatus::Completed)
        ->and($transfer->fresh()->hasBeenFunded())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments');
});

it('retryFunding marks completed without a fund POST when payments already exist', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(transferStateJson()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([[
            'id' => 987654321, 'method' => 'BALANCE', 'timeCreated' => '2026-11-10T10:00:00.000Z',
        ]]),
    ]);
    $transfer = makeTransferRow(['funding_status' => FundingStatus::Unknown]);

    $transfer->retryFunding();

    expect($transfer->fresh()->funding_status)->toBe(FundingStatus::Completed)
        ->and($transfer->fresh()->hasBeenFunded())->toBeTrue();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/payments'));
});

it('retryFunding throws when Wise is no longer waiting for funding', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(transferStateJson('incoming_payment_initiated')),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
    ]);
    $transfer = makeTransferRow(['funding_status' => FundingStatus::Unknown]);

    expect(fn () => $transfer->retryFunding())
        ->toThrow(WiseException::class, 'cannot be funded');
});

it('resumes a stale quoted row during the sync command', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferStateJson()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(transferStateJson()),
    ]);
    $transfer = makeQuotedTransfer('default', 'invoice-sync', now()->subMinutes(20));

    test()->artisan('wise:transfers:sync')->assertExitCode(0);

    expect($transfer->fresh()->status)->toBe(TransferStatus::AwaitingFunding)
        ->and($transfer->fresh()->wise_transfer_id)->toBe(16521632);
});

it('leaves a fresh quoted row alone during the sync command', function () {
    Http::preventStrayRequests();
    $transfer = makeQuotedTransfer('default', 'invoice-fresh');

    test()->artisan('wise:transfers:sync')->assertExitCode(0);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Quoted);
});

it('skips the resume pass with --no-resume', function () {
    Http::preventStrayRequests();
    $transfer = makeQuotedTransfer('default', 'invoice-no-resume', now()->subMinutes(20));

    test()->artisan('wise:transfers:sync', ['--no-resume' => true])->assertExitCode(0);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Quoted);
});

it('filters the sync by profile', function () {
    config()->set('wise.profiles.secondary', ['profile_id' => 456]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferStateJson()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(transferStateJson()),
    ]);
    $default = makeQuotedTransfer('default', 'invoice-p-default', now()->subMinutes(20));
    $secondary = makeQuotedTransfer('secondary', 'invoice-p-secondary', now()->subMinutes(20));

    test()->artisan('wise:transfers:sync', ['--profile' => 'default'])->assertExitCode(0);

    expect($default->fresh()->status)->toBe(TransferStatus::AwaitingFunding)
        ->and($secondary->fresh()->status)->toBe(TransferStatus::Quoted);
});

it('uses the configured transfer model class for the sync resume pass', function () {
    config()->set('wise.models.transfer', SyncThrowingTransfer::class);
    makeQuotedTransfer('default', 'invoice-custom', now()->subMinutes(20));

    test()->artisan('wise:transfers:sync')->assertExitCode(1);
});

it('reports created transfers with unknown funding', function () {
    WiseTransfer::factory()->fundingUnknown()->create([
        'profile_id' => 101,
        'account' => 'default',
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => 'invoice-unknown',
        'status' => TransferStatus::Created,
        'wise_status' => 'incoming_payment_waiting',
        'funding_mode' => FundingMode::Api,
        'funding_status' => FundingStatus::Unknown,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'target_amount' => 8550,
    ]);

    test()->artisan('wise:transfers:sync')
        ->expectsOutputToContain('UNKNOWN/REJECTED funding')
        ->assertExitCode(0);
});

it('returns failure when a row fails to resume', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response(['errors' => [['code' => 'internal', 'message' => 'boom']]], 500)]);
    makeQuotedTransfer('default', 'invoice-fail', now()->subMinutes(20));

    test()->artisan('wise:transfers:sync')->assertExitCode(1);
});

it('pending scope includes drafts and quotes and excludes terminal and sent states', function () {
    $draft = WiseTransfer::factory()->pending()->create(['profile_id' => 101, 'account' => 'default']);
    $quoted = WiseTransfer::factory()->quoted()->create(['profile_id' => 101, 'account' => 'default']);
    $sent = makeTransferRow(['wise_status' => 'outgoing_payment_sent', 'wise_transfer_id' => 16521633]);
    $failed = makeTransferRow(['wise_status' => 'cancelled', 'wise_transfer_id' => 16521634]);
    $failedToCreate = WiseTransfer::factory()->failedToCreate()->create(['profile_id' => 101, 'account' => 'default']);

    $ids = WiseTransfer::query()->pending()->pluck('id')->all();

    expect($ids)->toContain($draft->id, $quoted->id)
        ->not->toContain($sent->id, $failed->id, $failedToCreate->id);
});

it('marks the SendPayout job failed for a permanent requirements error', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response(wiseFixture('transfer_requirements'))]);
    $transfer = makeQuotedTransfer('default', 'invoice-job');

    $job = new SendPayout($transfer);
    $job->withFakeQueueInteractions()->handle();

    $job->assertFailed();
});

it('rethrows connection failures so the SendPayout job retries', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfer-requirements' => fn () => throw new ConnectionException('Connection refused')]);
    $transfer = makeQuotedTransfer('default', 'invoice-job-retry');

    $job = new SendPayout($transfer);

    expect(fn () => $job->withFakeQueueInteractions()->handle())
        ->toThrow(ConnectionFailed::class);
});

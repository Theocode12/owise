<?php

use Brick\Money\Money as BrickMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Events\TransferAwaitingFunding;
use Owise\Events\TransferCreated;
use Owise\Events\TransferFunded;
use Owise\Exceptions\ConnectionFailed;
use Owise\Exceptions\IdempotencyConflict;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\RecipientOwnershipMismatch;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;
use Owise\Exceptions\TransferFundingOutcomeUnknown;
use Owise\Exceptions\TransferFundingRejected;
use Owise\Exceptions\TransferRequirementsMissing;
use Owise\Exceptions\WiseApiError;
use Owise\Jobs\SendPayout;
use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;
use Owise\Support\Money;
use Owise\Tests\Fixtures\Models\Freelancer;

beforeEach(function () {
    wiseProfile(['funding' => 'api']);

    if (! Schema::hasTable('freelancers')) {
        Schema::create('freelancers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
});

function makePayoutFreelancer(string $name = 'Alex Freelancer', string $email = 'alex@example.com'): Freelancer
{
    return Freelancer::query()->create(['name' => $name, 'email' => $email]);
}

function makePayoutRecipient(Freelancer $payable, string $currency = 'EUR', int $wiseRecipientId = 40000000): WiseRecipient
{
    return WiseRecipient::factory()->create([
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'profile_name' => 'default',
        'profile_id' => 101,
        'wise_recipient_id' => $wiseRecipientId,
        'currency' => $currency,
        'type' => 'iban',
        'holder_name' => 'Jane Doe',
        'summary' => "{$currency} IBAN •••• 1234",
        'is_default' => true,
        'active' => true,
    ]);
}

function quoteResponse(string $source = 'GBP', string $target = 'EUR', float $sourceAmount = 100.0, float $targetAmount = 85.5, float $rate = 0.855, string $preferredPayIn = 'BALANCE'): array
{
    return [
        'id' => 'quote-111',
        'sourceCurrency' => $source,
        'targetCurrency' => $target,
        'sourceAmount' => $sourceAmount,
        'targetAmount' => $targetAmount,
        'payOut' => 'BANK_TRANSFER',
        'preferredPayIn' => $preferredPayIn,
        'rate' => $rate,
        'rateType' => 'FIXED',
        'createdTime' => '2026-11-10T10:00:00Z',
        'status' => 'PENDING',
        'paymentOptions' => [[
            'disabled' => false,
            'payIn' => $preferredPayIn,
            'payOut' => 'BANK_TRANSFER',
            'sourceAmount' => $sourceAmount,
            'targetAmount' => $targetAmount,
            'sourceCurrency' => $source,
            'targetCurrency' => $target,
            'fee' => ['total' => 0.77],
        ]],
    ];
}

function transferResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 16521632,
        'status' => 'incoming_payment_waiting',
        'sourceCurrency' => 'GBP',
        'sourceValue' => 100,
        'targetCurrency' => 'EUR',
        'targetValue' => 85.5,
        'rate' => 0.855,
        'reference' => 'Invoice 42',
        'quoteUuid' => 'quote-111',
        'targetAccount' => 40000000,
    ], $overrides);
}

function fundingResponse(string $status = 'COMPLETED', array $extra = []): array
{
    return array_merge(['type' => 'BALANCE', 'status' => $status, 'balanceTransactionId' => 987654321], $extra);
}

function happyPathHttpFake(): void
{
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::sequence()
            ->push([])
            ->push([[
                'id' => 987654321,
                'method' => 'BALANCE',
                'amount' => 100,
                'currency' => 'GBP',
                'timeCreated' => '2026-11-10T10:00:00.000Z',
                'timeUpdated' => '2026-11-10T10:00:00.000Z',
            ]]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
}

it('runs the happy path: draft → quoted → created → funded with events', function () {
    Event::fake([TransferCreated::class, TransferFunded::class]);
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->reference('Invoice 42')
        ->idempotencyKey('invoice-42')
        ->send();

    expect($transfer)->toBeInstanceOf(WiseTransfer::class)
        ->and($transfer->status)->toBe(TransferStatus::Created)
        ->and($transfer->wise_status)->toBe('incoming_payment_waiting')
        ->and($transfer->wise_quote_id)->toBe('quote-111')
        ->and($transfer->wise_transfer_id)->toBe(16521632)
        ->and($transfer->source_amount)->toBe(10000)
        ->and($transfer->target_amount)->toBe(8550)
        ->and($transfer->fee_amount)->toBe(77)
        ->and((float) $transfer->rate)->toBe(0.855)
        ->and($transfer->hasBeenFunded())->toBeTrue()
        ->and($transfer->funded_at)->not->toBeNull()
        ->and($transfer->funded_at?->format('Y-m-d H:i:s'))->toBe('2026-11-10 10:00:00')
        ->and($transfer->funding_mode)->toBe(FundingMode::Api)
        ->and($transfer->payable->is($payable))->toBeTrue();

    Event::assertDispatched(TransferCreated::class, fn (TransferCreated $event): bool => $event->transfer->is($transfer));
    Event::assertDispatched(TransferFunded::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'
        && $request->data()['customerTransactionId'] === $transfer->customer_transaction_id
        && $request->data()['targetAccount'] === 40000000
        && $request->data()['quoteUuid'] === 'quote-111'
        && $request->data()['details'] === ['reference' => 'Invoice 42']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['preferredPayIn'] === 'BALANCE');
});

it('fills missing top-level quote amounts from the selected payment option', function () {
    $quote = quoteResponse(preferredPayIn: 'BANK_TRANSFER');
    $quote['sourceAmount'] = null;
    $quote['targetAmount'] = null;
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response($quote),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->fund(false)
        ->idempotencyKey('invoice-quote-fill')
        ->send();

    expect($transfer->source_amount)->toBe(10000)
        ->and($transfer->target_amount)->toBe(8550)
        ->and($transfer->fee_amount)->toBe(77);
});

it('previews a quote without persisting a transfer', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse())]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $quote = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->preview();

    expect($quote->id)->toBe('quote-111')
        ->and(WiseTransfer::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['targetAmount'] === 85.5
        && $request->data()['payOut'] === 'BANK_TRANSFER'
        && $request->data()['preferredPayIn'] === 'BALANCE');
});

it('uses validated quote options and lets callers override the funding-mode pay-in default', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse(preferredPayIn: 'BANK_TRANSFER'))]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->quoteOptions([
            'preferredPayIn' => 'BANK_TRANSFER',
            'paymentMetadata' => ['transferPurpose' => 'SERVICES'],
        ])
        ->preview();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['preferredPayIn'] === 'BANK_TRANSFER'
        && $request->data()['paymentMetadata'] === ['transferPurpose' => 'SERVICES']);
});

it('persists a draft and dispatches a queued job with sendLater', function () {
    Queue::fake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-later')->sendLater();

    expect($transfer->exists)->toBeTrue()
        ->and($transfer->status)->toBe(TransferStatus::Draft)
        ->and(WiseTransfer::query()->count())->toBe(1);

    Queue::assertPushed(SendPayout::class, fn (SendPayout $job): bool => $job->transfer->is($transfer));
});

it('sends the same idempotency key once, creating one transfer', function () {
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $first = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-42')->send();
    $second = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-42')->send();

    expect($second->is($first))->toBeTrue()
        ->and(WiseTransfer::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers', 1);
});

it('raises an idempotency conflict when the key is reused with a different intent', function () {
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-42')->send();

    expect(fn () => $payable->payout(BrickMoney::of('90.00', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-42')->send())
        ->toThrow(IdempotencyConflict::class);
});

it('treats quote metadata as part of payout idempotency intent', function () {
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')->to($recipient)->idempotencyKey('invoice-quote-meta')->transferNature('GOODS')->send();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['paymentMetadata'] === ['transferNature' => 'GOODS']);

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')->to($recipient)->idempotencyKey('invoice-quote-meta')->transferNature('SERVICES')->send())
        ->toThrow(IdempotencyConflict::class);
});

it('rejects changed transfer requirement details after the Wise transfer exists', function () {
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')->to($recipient)->idempotencyKey('invoice-details')
        ->transferDetails(['sourceOfFunds' => 'salary'])->send();

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')->to($recipient)->idempotencyKey('invoice-details')
        ->transferDetails(['sourceOfFunds' => 'savings'])->send())
        ->toThrow(IdempotencyConflict::class, 'cannot be changed');
});

it('enforces the per-payable idempotency key with a database unique index', function () {
    $payable = makePayoutFreelancer();
    $row = [
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'profile_id' => 101,
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => 'invoice-42',
        'status' => TransferStatus::Draft,
        'funding_mode' => FundingMode::Api,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'target_amount' => 8550,
    ];
    WiseTransfer::factory()->create($row);

    expect(fn () => WiseTransfer::factory()->create([
        ...$row,
        'customer_transaction_id' => (string) Str::uuid(),
    ]))->toThrow(QueryException::class);
});

it('re-POSTs the same customer transaction id when a transfer create times out', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::sequence()
            ->push(['errors' => [['code' => 'internal', 'message' => 'boom']]], 500)
            ->push(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-timeout')->send();
        test()->fail('Expected the transfer create to fail.');
    } catch (WiseApiError) {
        // 5xx on transfer create: the payout stays resumable in `quoted`.
    }

    $draft = WiseTransfer::query()->sole();
    $customerTransactionId = $draft->customer_transaction_id;
    expect($draft->status)->toBe(TransferStatus::Quoted);

    $resumed = $draft->resume();

    expect($resumed->status)->toBe(TransferStatus::Created)
        ->and($resumed->wise_transfer_id)->toBe(16521632);

    $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers')->values();
    expect($posts)->toHaveCount(2)
        ->and($posts[0][0]->data()['customerTransactionId'])->toBe($customerTransactionId)
        ->and($posts[1][0]->data()['customerTransactionId'])->toBe($customerTransactionId);
});

it('throws TransferRequirementsMissing when required transfer details are absent and resumes after retry', function () {
    Event::fake([TransferCreated::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::sequence()
            ->push(wiseFixture('transfer_requirements'))
            ->push([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-42')->send();
        test()->fail('Expected TransferRequirementsMissing.');
    } catch (TransferRequirementsMissing $exception) {
        expect($exception->form->needsRefresh('transferPurpose'))->toBeTrue()
            ->and($exception->transfer->status)->toBe(TransferStatus::Quoted);
    }

    $resumed = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->idempotencyKey('invoice-42')
        ->transferDetails([
            'transferPurpose' => 'verification.transfers.purpose.other',
            'sourceOfFunds' => 'verification.source.of.funds.salary',
        ])
        ->send();

    expect($resumed->status)->toBe(TransferStatus::Created)
        ->and($resumed->meta['transfer_details'])->toBe([
            'transferPurpose' => 'verification.transfers.purpose.other',
            'sourceOfFunds' => 'verification.source.of.funds.salary',
        ]);
    Event::assertDispatched(TransferCreated::class);
});

it('rejects a recipient model owned by a different payable', function () {
    Http::preventStrayRequests();
    $payable = makePayoutFreelancer('Payable One', 'one@example.com');
    $otherPayable = makePayoutFreelancer('Payable Two', 'two@example.com');
    $recipient = makePayoutRecipient($otherPayable);

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->send())
        ->toThrow(RecipientOwnershipMismatch::class);
});

it('keeps manual funding awaiting funding and never calls payments', function () {
    Event::fake([TransferAwaitingFunding::class, TransferFunded::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse(preferredPayIn: 'BANK_TRANSFER')),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->fund(false)
        ->idempotencyKey('invoice-manual')
        ->send();

    expect($transfer->status)->toBe(TransferStatus::AwaitingFunding)
        ->and($transfer->wise_status)->toBe('incoming_payment_waiting')
        ->and($transfer->hasBeenFunded())->toBeFalse()
        ->and($transfer->awaitingFunding())->toBeTrue()
        ->and($transfer->isCancellable())->toBeTrue();

    Event::assertDispatched(TransferAwaitingFunding::class);
    Event::assertNotDispatched(TransferFunded::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['preferredPayIn'] === 'BANK_TRANSFER');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/payments'));
});

it('records a created funding attempt without marking funded_at', function () {
    Event::fake([TransferFunded::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('CREATED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-created')->send();

    expect($transfer->funding_status)->toBe(FundingStatus::Created)
        ->and($transfer->hasBeenFunded())->toBeFalse()
        ->and($transfer->funding_attempted_at)->not->toBeNull();

    Event::assertNotDispatched(TransferFunded::class);
});

it('maps a rejected funding result to a typed exception preserving Wise details', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('REJECTED', [
            'errorCode' => 'balance.payment-option-unavailable',
            'errorMessage' => 'Insufficient funds',
        ]), 201),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-rejected')->send();
        test()->fail('Expected TransferFundingRejected.');
    } catch (TransferFundingRejected $exception) {
        expect($exception->errorCode)->toBe('balance.payment-option-unavailable')
            ->and($exception->errorMessage)->toBe('Insufficient funds');
    }

    expect(WiseTransfer::query()->sole()->funding_status)->toBe(FundingStatus::Rejected);
});

it('reconciles an already-completed funding before retrying instead of double-funding', function () {
    Event::fake([TransferFunded::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::sequence()
            ->push([])
            ->push([[
                'id' => 987654321,
                'method' => 'BALANCE',
                'amount' => 100,
                'currency' => 'GBP',
                'timeCreated' => '2026-11-10T10:00:00.000Z',
                'timeUpdated' => '2026-11-10T10:00:00.000Z',
            ]]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(['errors' => [['code' => 'internal', 'message' => 'timeout']]], 500),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-reconcile')->send();
        test()->fail('Expected the funding to time out.');
    } catch (WiseApiError) {
        // ambiguous funding timeout
    }

    $transfer = WiseTransfer::query()->sole();
    expect($transfer->status)->toBe(TransferStatus::Created);

    $transfer->resume();

    expect($transfer->fresh()->hasBeenFunded())->toBeTrue();
    Event::assertDispatched(TransferFunded::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments', 1);
});

it('does not repeat funding when an ambiguous attempt is absent from the completed payments list', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(['errors' => [['code' => 'internal', 'message' => 'timeout']]], 500),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-ambiguous')->send();
        test()->fail('Expected the funding to time out.');
    } catch (WiseApiError) {
        // The request may have been accepted even though Wise's response timed out.
    }

    $transfer = WiseTransfer::query()->sole();
    expect($transfer->funding_status)->toBe(FundingStatus::Pending);

    expect(fn () => $transfer->resume())->toThrow(TransferFundingOutcomeUnknown::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments', 1);
    expect($transfer->fresh()->funding_status)->toBe(FundingStatus::Unknown);
});

it('resolves the source currency from config when ->from() is omitted', function () {
    config()->set('wise.payouts.source_currency', 'GBP');
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))->to($recipient)->idempotencyKey('invoice-config')->send();

    expect($transfer->source_currency)->toBe('GBP');
});

it('requires a source currency', function () {
    config()->set('wise.payouts.source_currency', null);
    Http::preventStrayRequests();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))->to($recipient)->send())
        ->toThrow(InvalidConfiguration::class, 'source currency');
});

it('stores minor units correctly for 0, 2 and 3 decimal currencies', function () {
    expect(Money::toMinor(BrickMoney::of('1500', 'JPY')))->toBe(1500)
        ->and(Money::toMinor(BrickMoney::of('85.50', 'EUR')))->toBe(8550)
        ->and(Money::toMinor(BrickMoney::of('1.234', 'KWD')))->toBe(1234);
});

it('resolves the local recipient from recipient_id rather than wise_recipient_id', function () {
    happyPathHttpFake();
    $payable = makePayoutFreelancer();
    // The local id differs from the Wise recipient id used as `targetAccount`.
    $recipient = makePayoutRecipient($payable);

    $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
        ->from('GBP')
        ->to($recipient)
        ->idempotencyKey('invoice-recipient')
        ->send();

    expect($transfer->recipient_id)->toBe($recipient->id)
        ->and($transfer->recipient->is($recipient))->toBeTrue();

    $recipient->delete();

    expect($transfer->fresh()->recipient->is($recipient))->toBeTrue();
});

it('requires an idempotency key before sending a payout', function () {
    Http::preventStrayRequests();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->send())
        ->toThrow(InvalidArgumentException::class, 'A payout idempotency key is required');

    Http::assertNothingSent();
});

it('rejects an over-long idempotency key', function () {
    Http::preventStrayRequests();
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    expect(fn () => $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey(str_repeat('x', 256))->send())
        ->toThrow(InvalidArgumentException::class, 'cannot be longer than 255 characters');

    Http::assertNothingSent();
});

it('never creates a second transfer when funding fails and the same key is retried', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::sequence()
            ->push([])
            ->push([[
                'id' => 987654321,
                'method' => 'BALANCE',
                'amount' => 100,
                'currency' => 'GBP',
                'timeCreated' => '2026-11-10T10:00:00.000Z',
            ]]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => fn () => throw new ConnectionException('Connection refused'),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-retry')->send();
        test()->fail('Expected the funding to fail.');
    } catch (ConnectionFailed) {
        // connection dropped during funding
    }

    $transfer = WiseTransfer::query()->sole();
    expect($transfer->status)->toBe(TransferStatus::Created);

    $resumed = $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-retry')->send();

    expect($resumed->is($transfer))->toBeTrue()
        ->and($resumed->fresh()->hasBeenFunded())->toBeTrue();

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers/16521632/payments'))->toHaveCount(2);
});

it('classifies an SCA funding rejection as rejected, then refunds on resume', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::sequence()
            ->push([])
            ->push([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::sequence()
            ->push('', 403, ['x-2fa-approval' => 'ott-123', 'x-2fa-approval-result' => 'REJECTED'])
            ->push(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    try {
        $payable->payout(BrickMoney::of('85.50', 'EUR'))->from('GBP')->to($recipient)->idempotencyKey('invoice-sca')->send();
        test()->fail('Expected the funding to be rejected for SCA.');
    } catch (StrongCustomerAuthenticationRequired $e) {
        expect($e->oneTimeToken)->toBe('ott-123');
    }

    $transfer = WiseTransfer::query()->sole();
    expect($transfer->funding_status)->toBe(FundingStatus::Rejected)
        ->and($transfer->failure_reason)->not->toBeNull();

    $transfer->resume();

    expect($transfer->fresh()->hasBeenFunded())->toBeTrue()
        ->and($transfer->fresh()->funding_status)->toBe(FundingStatus::Completed);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments'))->toHaveCount(2);
});

it('re-quotes and creates once when a quote expired before any create attempt', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = WiseTransfer::factory()->quoted()->create([
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'wise_recipient_id' => $recipient->wise_recipient_id,
        'recipient_id' => $recipient->id,
        'account' => 'default',
        'profile_id' => 101,
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => 'invoice-expired',
        'wise_quote_id' => 'quote-old',
        'status' => TransferStatus::Quoted,
        'funding_mode' => FundingMode::Api,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'target_amount' => 8550,
        'reference' => 'Invoice 42',
        'meta' => [
            'amount_is_source' => false,
            'quote_expires_at' => CarbonImmutable::now()->subMinute()->format(DATE_ATOM),
        ],
    ]);

    $transfer->resume();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Created);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'))->toHaveCount(1);
});

it('does not re-quote when the expired quote already attempted a create that succeeded', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::response(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = WiseTransfer::factory()->quoted()->create([
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'wise_recipient_id' => $recipient->wise_recipient_id,
        'recipient_id' => $recipient->id,
        'account' => 'default',
        'profile_id' => 101,
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => 'invoice-expired-attempted',
        'wise_quote_id' => 'quote-old',
        'status' => TransferStatus::Quoted,
        'funding_mode' => FundingMode::Api,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'target_amount' => 8550,
        'reference' => 'Invoice 42',
        'meta' => [
            'amount_is_source' => false,
            'quote_expires_at' => CarbonImmutable::now()->subMinute()->format(DATE_ATOM),
            'transfer_create_attempted_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
        ],
    ]);

    $transfer->resume();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Created);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'))->toHaveCount(1);
});

it('re-quotes once when the expired-quote create is rejected as never-created', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(quoteResponse()),
        WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/transfers' => Http::sequence()
            ->push(['errors' => [['code' => 'quote.expired', 'message' => 'quote expired']]], 422)
            ->push(transferResponse()),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/payments' => Http::response([]),
        WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(fundingResponse('COMPLETED')),
    ]);
    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $transfer = WiseTransfer::factory()->quoted()->create([
        'payable_type' => $payable->getMorphClass(),
        'payable_id' => $payable->getKey(),
        'wise_recipient_id' => $recipient->wise_recipient_id,
        'recipient_id' => $recipient->id,
        'account' => 'default',
        'profile_id' => 101,
        'customer_transaction_id' => (string) Str::uuid(),
        'idempotency_key' => 'invoice-expired-422',
        'wise_quote_id' => 'quote-old',
        'status' => TransferStatus::Quoted,
        'funding_mode' => FundingMode::Api,
        'source_currency' => 'GBP',
        'target_currency' => 'EUR',
        'target_amount' => 8550,
        'reference' => 'Invoice 42',
        'meta' => [
            'amount_is_source' => false,
            'quote_expires_at' => CarbonImmutable::now()->subMinute()->format(DATE_ATOM),
            'transfer_create_attempted_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
        ],
    ]);

    $transfer->resume();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Created);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'))->toHaveCount(2);
});

it('recovers the winning row when a concurrent same-key draft collides', function () {
    Queue::fake();
    config()->set('wise.payouts.source_currency', 'GBP');

    // Point the default connection and a second "race" connection at the same SQLite file so a
    // concurrent request can commit its winning row independently of this request's transaction.
    $file = str_replace('\\', '/', tempnam(sys_get_temp_dir(), 'owise-race-'));
    DB::purge('testing');
    $base = config('database.connections.testing');
    $base['database'] = $file;
    config()->set('database.connections.testing', $base);
    config()->set('database.connections.race', $base);

    $this->artisan('migrate', ['--database' => 'testing'])->run();
    Schema::connection('testing')->create('freelancers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });

    $payable = makePayoutFreelancer();
    $recipient = makePayoutRecipient($payable);

    $winnerId = null;
    $listener = function (WiseTransfer $model) use (&$winnerId): void {
        if ($winnerId !== null) {
            return;
        }

        $attributes = $model->getAttributes();
        $attributes['customer_transaction_id'] = (string) Str::uuid();
        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $value instanceof BackedEnum
                ? $value->value
                : (is_array($value) ? json_encode($value) : $value);
        }

        $winnerId = DB::connection('race')->table('wise_transfers')->insertGetId($attributes);
    };
    Event::listen('eloquent.creating: '.WiseTransfer::class, $listener);

    try {
        $transfer = $payable->payout(BrickMoney::of('85.50', 'EUR'))
            ->from('GBP')
            ->to($recipient)
            ->idempotencyKey('invoice-race')
            ->sendLater();
    } finally {
        Event::forget('eloquent.creating: '.WiseTransfer::class);
    }

    expect($transfer->getKey())->toBe((int) $winnerId)
        ->and(WiseTransfer::query()->count())->toBe(1);
});

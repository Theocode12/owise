<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Owise\Data\Transfer;

const CUSTOMER_TRANSACTION_ID = '54a6bc09-cef9-49a8-9041-f1f0c654cd88';
const QUOTE_UUID = '8fa9be20-ba43-4b15-abbb-9424e1481050';

it('validates transfer requirements', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfer-requirements' => Http::response(wiseFixture('transfer_requirements'))]);

    $form = wiseProfile()->transfers()->requirements(8692237, QUOTE_UUID, ['reference' => 'Invoice 42']);

    expect($form->type('transfer')?->field('transferPurpose')?->refreshRequirementsOnChange)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['targetAccount' => 8692237, 'quoteUuid' => QUOTE_UUID, 'details' => ['reference' => 'Invoice 42']]);
});

it('creates a transfer with the caller supplied customer transaction id', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/transfers' => Http::response(wiseFixture('transfer'))]);

    $transfer = wiseProfile()->transfers()->create(8692237, QUOTE_UUID, CUSTOMER_TRANSACTION_ID, ['reference' => 'Testing']);

    expect($transfer)->toBeInstanceOf(Transfer::class)
        ->and($transfer->id)->toBe(16521632)
        ->and($transfer->status)->toBe('incoming_payment_waiting')
        ->and($transfer->reference)->toBe('Testing')
        ->and((string) $transfer->targetValue?->getAmount())->toBe('150.00')
        ->and($transfer->targetValue?->getCurrency()->getCurrencyCode())->toBe('GBP')
        ->and($transfer->created?->format(DATE_ATOM))->toBe('2017-11-24T10:47:49+00:00')
        ->and($transfer->customerTransactionId)->toBe(CUSTOMER_TRANSACTION_ID);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/transfers'
        && $request->data() === [
            'targetAccount' => 8692237,
            'quoteUuid' => QUOTE_UUID,
            'customerTransactionId' => CUSTOMER_TRANSACTION_ID,
            'details' => ['reference' => 'Testing'],
        ]);
});

it('rejects a customer transaction id that is not a uuid before calling Wise', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->transfers()->create(1, QUOTE_UUID, 'invoice-42'))
        ->toThrow(InvalidArgumentException::class, 'customerTransactionId must be a UUID');
});

it('finds, lists and cancels transfers', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/transfers/16521632' => Http::response(wiseFixture('transfer')),
        WISE_SANDBOX.'/2026Q4/transfers/16521632/cancel' => Http::response([...wiseFixture('transfer'), 'status' => 'cancelled']),
        WISE_SANDBOX.'/2026Q4/transfers?*' => Http::response(wiseFixture('transfer_list')),
    ]);
    $transfers = wiseProfile()->transfers();

    $found = $transfers->find(16521632);
    $list = $transfers->list(
        status: ['processing', 'funds_converted'],
        createdDateStart: new DateTimeImmutable('2026-01-01'),
        createdDateEnd: new DateTimeImmutable('2026-01-31'),
        limit: 10,
    );
    $cancelled = $transfers->cancel(16521632);

    expect($found->id)->toBe(16521632)
        ->and($list)->toHaveCount(count(wiseFixture('transfer_list')))
        ->and($list[0])->toBeInstanceOf(Transfer::class)
        ->and($cancelled->status)->toBe('cancelled');

    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX
        .'/2026Q4/transfers?profile=101&status=processing%2Cfunds_converted&createdDateStart=2026-01-01&createdDateEnd=2026-01-31&limit=10&offset=0');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === WISE_SANDBOX.'/2026Q4/transfers/16521632/cancel');
});

it('funds a transfer from the balance', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/profiles/101/transfers/16521632/payments' => Http::response(wiseFixture('funding'), 201)]);

    $result = wiseProfile()->transfers()->fund(16521632);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->balanceTransactionId)->toBe(987654321);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['type' => 'BALANCE']);
});

it('exposes rejected funding returned as a successful response', function () {
    Http::fake(['*' => Http::response(['type' => 'BALANCE', 'status' => 'REJECTED', 'errorCode' => 'balance.payment-option-unavailable'], 201)]);

    $result = wiseProfile()->transfers()->fund(16521632);

    expect($result->isRejected())->toBeTrue()->and($result->errorCode)->toBe('balance.payment-option-unavailable');
});

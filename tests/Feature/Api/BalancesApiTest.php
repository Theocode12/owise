<?php

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Owise\Data\Balance;
use Owise\Events\BalanceConverted;
use Owise\Exceptions\BalanceNotActionable;
use Owise\Support\Idempotency;

const IDEMPOTENCY_UUID = '0f3a4a1c-7a8b-4c50-9d0e-111111111111';

it('lists, finds and creates balances', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balances?*' => Http::response(wiseFixture('balances')),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/200001' => Http::response(wiseFixture('balance')),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances' => Http::response(wiseFixture('balance'), 201),
    ]);
    $balances = wiseProfile()->balances();

    $list = $balances->list(['STANDARD', 'SAVINGS']);
    $balance = $balances->find(200001);
    $balances->create('eur', IDEMPOTENCY_UUID, name: 'Payroll');

    expect($list[0])->toBeInstanceOf(Balance::class)
        ->and((string) $balance->amount?->getAmount())->toBe('310.86')
        ->and($balance->amount?->getCurrency()->getCurrencyCode())->toBe('EUR')
        ->and($balance->creationTime?->format('Y-m-d'))->toBe('2020-05-20')
        ->and($balance->investmentState)->toBe('NOT_INVESTED')
        ->and($balance->isActionable())->toBeTrue()
        ->and((string) $balance->amount()->getAmount())->toBe('310.86')
        ->and((string) $balance->reserved()->getAmount())->toBe('0.00')
        ->and((string) $balance->cash()->getAmount())->toBe('310.86')
        ->and((string) $balance->totalWorth()->getAmount())->toBe('310.86');

    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/balances?types=STANDARD%2CSAVINGS');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('X-idempotence-uuid', IDEMPOTENCY_UUID)
        && $request->data() === ['currency' => 'EUR', 'type' => 'STANDARD', 'name' => 'Payroll']);
});

it('exposes all, for and open helpers', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balances?*' => Http::response(wiseFixture('balances')),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances' => Http::response(wiseFixture('balance'), 201),
    ]);
    $balances = wiseProfile()->balances();

    $all = $balances->all();
    $eur = $balances->for('eur');
    $missing = $balances->for('JPY');
    $opened = $balances->open('eur', idempotencyKey: 'payroll-eur');

    expect($all)->toHaveCount(1)
        ->and($eur)->toBeInstanceOf(Balance::class)
        ->and($missing)->toBeNull()
        ->and($opened->currency)->toBe('EUR');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('X-idempotence-uuid')
        && $request->data()['currency'] === 'EUR');
});

it('requires a name for savings balances', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->balances()->open('EUR', 'SAVINGS'))
        ->toThrow(InvalidArgumentException::class, 'SAVINGS');
});

it('closes a balance and reads capacity and total funds', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/200001' => Http::response('', 204),
        WISE_SANDBOX.'/2026Q4/profiles/101/balance-capacity*' => Http::response(wiseFixture('balance_capacity')),
        WISE_SANDBOX.'/2026Q4/profiles/101/total-funds/*' => Http::response(wiseFixture('total_funds')),
    ]);

    wiseProfile()->balances()->close(200001);
    $capacity = wiseProfile()->balances()->capacity('sgd');
    $funds = wiseProfile()->balances()->totalFunds('eur');

    expect($capacity->hasLimit)->toBeTrue()
        ->and((string) $capacity->depositLimit?->getAmount())->toBe('2000.00')
        ->and((string) $funds->totalWorth?->getAmount())->toBe('2000.00')
        ->and((string) $funds->totalAvailable?->getAmount())->toBe('2500.00')
        ->and((string) $funds->overdraftAvailable?->getAmount())->toBe('400.00')
        ->and($funds->overdraftAvailableByCurrency)->toHaveCount(1)
        ->and((string) $funds->overdraftAvailableByCurrency[0]->getAmount())->toBe('400.00');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/balance-capacity?currency=SGD');
    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/total-funds/EUR');
});

it('executes a quoted conversion and moves money with the idempotence header', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balance-movements' => Http::response(wiseFixture('balance_movement'), 201),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/1' => Http::response(array_merge(wiseFixture('balance'), ['id' => 1])),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/2' => Http::response(array_merge(wiseFixture('balance'), ['id' => 2])),
    ]);
    $balances = wiseProfile()->balances();

    $conversion = $balances->convertByQuote('11144c35-9fe8-4c32-b7fd-d05c2a7734bf', IDEMPOTENCY_UUID);
    $balances->move(1, 2, Money::of('25.50', 'EUR'), IDEMPOTENCY_UUID);

    expect($conversion->type)->toBe('CONVERSION')
        ->and((string) $conversion->targetAmount?->getAmount())->toBe('100.00')
        ->and($conversion->fees)->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-idempotence-uuid', IDEMPOTENCY_UUID)
        && $request->data() === ['quoteId' => '11144c35-9fe8-4c32-b7fd-d05c2a7734bf']);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-idempotence-uuid', IDEMPOTENCY_UUID)
        && $request->data() === ['sourceBalanceId' => 1, 'targetBalanceId' => 2, 'amount' => ['value' => 25.5, 'currency' => 'EUR']]);
});

it('converts money via a BALANCE quote with a deterministic idempotence header', function () {
    Event::fake([BalanceConverted::class]);
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response([
            'id' => 'quote-balance-1',
            'sourceCurrency' => 'GBP',
            'targetCurrency' => 'EUR',
            'sourceAmount' => 10,
            'targetAmount' => 11.5,
            'payOut' => 'BALANCE',
            'paymentOptions' => [],
        ], 201),
        WISE_SANDBOX.'/2026Q4/profiles/101/balance-movements' => Http::response(wiseFixture('balance_movement'), 201),
    ]);
    $balances = wiseProfile()->balances();

    $first = $balances->convert(Money::of('10', 'GBP'), 'EUR', 'invoice-42');
    $second = $balances->convert(Money::of('10', 'GBP'), 'EUR', 'invoice-42');

    expect($first->type)->toBe('CONVERSION');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2026Q4/profiles/101/quotes')
        && $request->data()['payOut'] === 'BALANCE'
        && $request->data()['sourceCurrency'] === 'GBP'
        && $request->data()['targetCurrency'] === 'EUR');

    $movements = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'balance-movements'))->values()->all();
    expect($movements)->toHaveCount(2);

    $uuids = array_map(fn (array $pair): string => $pair[0]->header('X-idempotence-uuid')[0] ?? '', $movements);
    expect($uuids[0])->not->toBe('')
        ->and($uuids[0])->toBe($uuids[1]);
    expect($movements[0][0]->data())->toBe(['quoteId' => 'quote-balance-1']);

    Event::assertDispatched(BalanceConverted::class, 2);
});

it('derives deterministic uuids from idempotency keys', function () {
    $one = Idempotency::forKey('invoice-42', 'default:101');
    $two = Idempotency::forKey('invoice-42', 'default:101');
    $otherScope = Idempotency::forKey('invoice-42', 'other:101');
    $passthrough = Idempotency::forKey(IDEMPOTENCY_UUID, 'default:101');
    $random = Idempotency::forKey(null, 'default:101');

    expect($one)->toBe($two)
        ->and($one)->not->toBe($otherScope)
        ->and($passthrough)->toBe(strtolower(IDEMPOTENCY_UUID))
        ->and($random)->toMatch('/^[0-9a-f-]{36}$/');
});

it('refuses to move from invested balances before any movement call', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/1' => Http::response(array_merge(wiseFixture('balance'), ['id' => 1, 'investmentState' => 'INVESTED'])),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/2' => Http::response(array_merge(wiseFixture('balance'), ['id' => 2])),
    ]);
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->balances()->move(1, 2, Money::of('5', 'EUR'), 'jar-move-1'))
        ->toThrow(BalanceNotActionable::class);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'balance-movements'));
});

it('refuses to move between different currencies before any movement call', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/1' => Http::response(array_merge(wiseFixture('balance'), ['id' => 1])),
        WISE_SANDBOX.'/2026Q4/profiles/101/balances/2' => Http::response(array_merge(wiseFixture('balance'), [
            'id' => 2,
            'currency' => 'GBP',
        ])),
    ]);
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->balances()->move(1, 2, Money::of('5', 'EUR'), 'jar-move-2'))
        ->toThrow(InvalidArgumentException::class, 'currencies to match');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'balance-movements'));
});

it('requires a uuid idempotence key for quoted conversions', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->balances()->convertByQuote('quote', 'retry-1'))
        ->toThrow(InvalidArgumentException::class, 'X-idempotence-uuid must be a UUID');
});

it('fetches a statement for an interval in UTC', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/profiles/101/balance-statements/64/statement.json*' => Http::response(wiseFixture('statement'))]);

    $statement = wiseProfile()->balances()->statement(
        64,
        'eur',
        new DateTimeImmutable('2018-03-01 01:00:00', new DateTimeZone('Europe/Berlin')),
        new DateTimeImmutable('2018-04-30T23:59:59.999Z'),
    );

    expect($statement->transactions)->toHaveCount(1)
        ->and((string) $statement->endOfStatementBalance?->getAmount())->toBe('9.94');

    Http::assertSent(fn (Request $request): bool => $request->url() === WISE_SANDBOX
        .'/2026Q4/profiles/101/balance-statements/64/statement.json?currency=EUR&intervalStart=2018-03-01T00%3A00%3A00.000Z&intervalEnd=2018-04-30T23%3A59%3A59.999Z&type=COMPACT');
});

it('validates statement intervals before any HTTP call', function () {
    Http::preventStrayRequests();
    $api = wiseProfile()->statements();

    expect(fn () => $api->get(64, 'EUR', new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2019-01-01')))
        ->toThrow(InvalidArgumentException::class, 'after the interval start');

    expect(fn () => $api->get(64, 'EUR', new DateTimeImmutable('2018-01-01'), new DateTimeImmutable('2019-06-01')))
        ->toThrow(InvalidArgumentException::class, '469 days');

    expect(fn () => $api->get(64, 'EUR', new DateTimeImmutable('2018-03-01'), new DateTimeImmutable('2018-04-01'), 'VERBOSE'))
        ->toThrow(InvalidArgumentException::class, 'COMPACT');
});

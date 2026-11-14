<?php

use Brick\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Owise\Data\Profile;
use Owise\Data\Quote;

it('lists and finds profiles', function () {
    Http::fake([
        WISE_SANDBOX.'/2026Q4/profiles' => Http::response(wiseFixture('profiles')),
        WISE_SANDBOX.'/2026Q4/profiles/14599371' => Http::response(wiseFixture('profiles')[1]),
    ]);

    $profiles = wiseProfile()->profiles()->list();
    $business = wiseProfile()->profiles()->find(14599371);

    expect($profiles)->toHaveCount(2)
        ->and($profiles[0])->toBeInstanceOf(Profile::class)
        ->and($profiles[0]->fullName)->toBe('Sarah Jenkins')
        ->and($business->isBusiness())->toBeTrue()
        ->and($business->raw['businessName'])->toBe('Innovate Solutions Ltd');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('X-External-Correlation-Id'));
});

it('creates a quote for the account profile and hydrates the full payload', function () {
    Http::fake([WISE_SANDBOX.'/2026Q4/profiles/101/quotes' => Http::response(wiseFixture('quote'))]);

    $quote = wiseProfile()->quotes()->create(
        'gbp',
        'usd',
        sourceAmount: Money::of('100.00', 'GBP'),
        payOut: 'BANK_TRANSFER',
        preferredPayIn: 'BALANCE',
        transferNature: 'MOVING_MONEY_BETWEEN_OWN_ACCOUNTS',
        options: [
            'payInMethod' => 'BALANCE',
            'creationContext' => ['flow' => 'MASS_PAY'],
        ],
    );

    expect($quote)->toBeInstanceOf(Quote::class)
        ->and($quote->id)->toBe('11144c35-9fe8-4c32-b7fd-d05c2a7734bf')
        ->and((string) $quote->sourceAmount?->getAmount())->toBe('100.00')
        ->and((string) $quote->targetAmount?->getAmount())->toBe('129.24')
        ->and($quote->rate)->toBe(1.30445)
        ->and($quote->preferredPayIn)->toBe('BANK_TRANSFER')
        ->and($quote->expirationTime?->format(DATE_ATOM))->toBe('2019-04-05T13:48:58+00:00')
        ->and($quote->paymentOptions)->toHaveCount(1)
        ->and((string) $quote->paymentOption('BANK_TRANSFER')?->fee?->getAmount())->toBe('0.77')
        ->and($quote->toArray()['sourceAmount'])->toBe(['amount' => '100.00', 'currency' => 'GBP'])
        ->and(json_encode($quote))->toBeJson();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WISE_SANDBOX.'/2026Q4/profiles/101/quotes'
        && $request->data()['sourceCurrency'] === 'GBP'
        && $request->data()['targetCurrency'] === 'USD'
        && $request->data()['sourceAmount'] === 100
        && $request->data()['payOut'] === 'BANK_TRANSFER'
        && $request->data()['preferredPayIn'] === 'BALANCE'
        && $request->data()['paymentMetadata'] === ['transferNature' => 'MOVING_MONEY_BETWEEN_OWN_ACCOUNTS']
        && $request->data()['payInMethod'] === 'BALANCE'
        && $request->data()['creationContext'] === ['flow' => 'MASS_PAY']);
});

it('rejects unsupported quote options and conflicting transfer nature metadata', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->quotes()->create('GBP', 'USD', 100, options: ['notAQuoteField' => true]))
        ->toThrow(InvalidArgumentException::class, 'Unsupported quote option')
        ->and(fn () => wiseProfile()->quotes()->create(
            'GBP',
            'USD',
            100,
            transferNature: 'GOODS',
            options: ['paymentMetadata' => ['transferNature' => 'SERVICES']],
        ))->toThrow(InvalidArgumentException::class, 'conflicts');
});

it('requires exactly one quote amount', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->quotes()->create('GBP', 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => wiseProfile()->quotes()->create('GBP', 'USD', 1, 2))->toThrow(InvalidArgumentException::class);
});

it('rejects quote Money amounts in a currency that disagrees with the quote', function () {
    Http::preventStrayRequests();

    expect(fn () => wiseProfile()->quotes()->create('GBP', 'USD', sourceAmount: Money::of(100, 'EUR')))
        ->toThrow(InvalidArgumentException::class, 'sourceAmount currency must match sourceCurrency (GBP)')
        ->and(fn () => wiseProfile()->quotes()->create('GBP', 'USD', targetAmount: Money::of(100, 'GBP')))
        ->toThrow(InvalidArgumentException::class, 'targetAmount currency must match targetCurrency (USD)');
});

it('finds and updates a quote with a merge patch', function () {
    $path = WISE_SANDBOX.'/2026Q4/profiles/101/quotes/11144c35-9fe8-4c32-b7fd-d05c2a7734bf';
    Http::fake([$path => Http::response(wiseFixture('quote'))]);

    wiseProfile()->quotes()->find('11144c35-9fe8-4c32-b7fd-d05c2a7734bf');
    wiseProfile()->quotes()->update('11144c35-9fe8-4c32-b7fd-d05c2a7734bf', targetAccount: 8692237);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $path);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === $path
        && $request->hasHeader('Content-Type', 'application/merge-patch+json')
        && json_decode($request->body(), true) === ['targetAccount' => 8692237]);
});

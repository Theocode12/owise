<?php

use Owise\Support\Money;

it('converts Wise decimals into money in the currency minor units', function (int|float|string $value, string $currency, string $expected, int $minor) {
    $money = Money::fromDecimal($value, $currency);

    expect(Money::toDecimal($money))->toBe($expected)
        ->and(Money::toMinor($money))->toBe($minor)
        ->and($money->getCurrency()->getCurrencyCode())->toBe(strtoupper($currency));
})->with([
    'float' => [129.24, 'USD', '129.24', 12924],
    'integer' => [100, 'gbp', '100.00', 10000],
    'zero-decimal currency' => [1500, 'JPY', '1500', 1500],
    'three-decimal currency' => ['1.234', 'KWD', '1.234', 1234],
    'rounds extra precision half up' => [0.125, 'EUR', '0.13', 13],
    'float binary noise' => [0.1 + 0.2, 'EUR', '0.30', 30],
]);

it('round-trips minor amounts and Wise numbers', function () {
    $money = Money::fromMinor(2550, 'EUR');

    expect(Money::toDecimal($money))->toBe('25.50')
        ->and(Money::toWiseNumber($money))->toBe(25.5)
        ->and(Money::toWiseNumber('10.10'))->toBe(10.1);
});

it('rejects non-numeric amounts', function () {
    expect(fn () => Money::fromDecimal('ten', 'EUR'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromDecimal(INF, 'EUR'))->toThrow(InvalidArgumentException::class);
});

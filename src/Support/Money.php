<?php

namespace Owise\Support;

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

/**
 * Creates and converts money values for Wise requests.
 */
final class Money
{
    /**
     * Create a money instance from a decimal amount.
     */
    public static function fromDecimal(int|float|string $value, string $currency): BrickMoney
    {
        return BrickMoney::of(self::decimalString($value), strtoupper($currency), roundingMode: RoundingMode::HalfUp);
    }

    /**
     * Create a money instance from a minor-unit amount.
     */
    public static function fromMinor(int $minorAmount, string $currency): BrickMoney
    {
        return BrickMoney::ofMinor($minorAmount, strtoupper($currency));
    }

    /**
     * Get the minor-unit amount for the given money instance.
     */
    public static function toMinor(BrickMoney $money): int
    {
        return $money->getMinorAmount()->toInt();
    }

    /**
     * Get the decimal amount for the given money instance.
     */
    public static function toDecimal(BrickMoney $money): string
    {
        return (string) $money->getAmount();
    }

    /**
     * Convert the given amount to a JSON number for Wise.
     *
     * Wise expects JSON numbers for amounts.
     */
    public static function toWiseNumber(BrickMoney|int|float|string $amount): float
    {
        $decimal = $amount instanceof BrickMoney ? self::toDecimal($amount) : self::decimalString($amount);

        return (float) $decimal;
    }

    /**
     * Format a money value for display, respecting the application locale.
     */
    public static function format(BrickMoney $money, ?string $locale = null): string
    {
        $locale ??= (string) config('app.locale', 'en');

        try {
            return $money->formatToLocale($locale);
        } catch (\Throwable) {
            return self::toDecimal($money).' '.$money->getCurrency()->getCurrencyCode();
        }
    }

    /**
     * Normalize the given value to a decimal string.
     *
     *
     * @throws InvalidArgumentException
     */
    private static function decimalString(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Amount must be a finite number.');
            }

            $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        $value = trim((string) $value);
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Amount '{$value}' is not a valid decimal number.");
        }

        return $value;
    }
}

<?php

namespace Owise\Support;

use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Reads typed values from decoded Wise payloads.
 */
final class Payload
{
    /**
     * Get a string value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = data_get($data, $key);

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Get an integer value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = data_get($data, $key);
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }

    /**
     * Get a float value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function float(array $data, string $key): ?float
    {
        $value = data_get($data, $key);

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    /**
     * Get a boolean value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = data_get($data, $key);

        return is_bool($value) ? $value : null;
    }

    /**
     * Get an array value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = data_get($data, $key);

        return is_array($value) ? $value : [];
    }

    /**
     * Get a list of arrays from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function list(array $data, string $key): array
    {
        return self::arrays(self::array($data, $key));
    }

    /**
     * Filter the given items to a list of arrays.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<array<array-key, mixed>>
     */
    public static function arrays(array $items): array
    {
        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Get a date value from the payload using dot notation.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::string($data, $key);
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get a money instance from a numeric amount and currency.
     *
     * Reads an amount whose currency is either a sibling key or a literal ISO code.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function money(array $data, string $amountKey, ?string $currency): ?BrickMoney
    {
        $amount = data_get($data, $amountKey);
        if ($currency === null || ! (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount)))) {
            return null;
        }

        try {
            return Money::fromDecimal($amount, $currency);
        } catch (UnknownCurrencyException) {
            return null;
        }
    }

    /**
     * Get a money instance from a Wise `{value, currency}` object.
     *
     * Reads a Wise `{value, currency}` money object.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function moneyObject(array $data, string $key): ?BrickMoney
    {
        return self::money($data, "{$key}.value", self::string($data, "{$key}.currency"));
    }
}

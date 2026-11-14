<?php

namespace Owise\Concerns;

use BackedEnum;
use Brick\Money\Money;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Serializes data objects to plain arrays.
 */
trait SerializesData
{
    /**
     * Get the array representation of the data, excluding the raw payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($key !== 'raw') {
                $data[$key] = self::serializeValue($value);
            }
        }

        return $data;
    }

    /**
     * Get the JSON serializable representation of the data.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize a single value to its plain representation.
     */
    private static function serializeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Money => [
                'amount' => (string) $value->getAmount(),
                'currency' => $value->getCurrency()->getCurrencyCode(),
            ],
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Arrayable => $value->toArray(),
            is_array($value) => array_map(self::serializeValue(...), $value),
            default => $value,
        };
    }
}

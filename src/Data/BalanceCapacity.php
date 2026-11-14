<?php

namespace Owise\Data;

use Brick\Money\Money;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise balance deposit capacity.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BalanceCapacity implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new balance capacity instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * Whether the balance has a deposit limit.
         *
         * @var bool
         */
        public bool $hasLimit,
        /**
         * The maximum deposit allowed.
         *
         * @var Money|null
         */
        public ?Money $depositLimit,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a balance capacity instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            hasLimit: Payload::bool($raw, 'hasLimit') ?? false,
            depositLimit: Payload::money($raw, 'depositLimit.amount', Payload::string($raw, 'depositLimit.currency')),
            raw: $raw,
        );
    }
}

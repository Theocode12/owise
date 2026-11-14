<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise balance statement movement.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BalanceMovement implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new balance movement instance.
     *
     * @param  list<Money>  $fees
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The movement identifier.
         *
         * @var int
         */
        public int $id,
        /**
         * The Wise movement type.
         *
         * @var string
         */
        public string $type,
        /**
         * The Wise movement state.
         *
         * @var string
         */
        public string $state,
        /**
         * The source amount moved.
         *
         * @var Money|null
         */
        public ?Money $sourceAmount,
        /**
         * The target amount received.
         *
         * @var Money|null
         */
        public ?Money $targetAmount,
        /**
         * The exchange rate applied to the movement.
         *
         * @var float|null
         */
        public ?float $rate,
        /**
         * The fees charged for the movement.
         *
         * @var list<Money>
         */
        public array $fees,
        /**
         * The time the movement was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $creationTime,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a balance movement instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $fees = [];
        foreach (Payload::list($raw, 'feeAmounts') as $fee) {
            $money = Payload::money($fee, 'value', Payload::string($fee, 'currency'));
            if ($money !== null) {
                $fees[] = $money;
            }
        }

        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            type: Payload::string($raw, 'type') ?? '',
            state: Payload::string($raw, 'state') ?? '',
            sourceAmount: Payload::moneyObject($raw, 'sourceAmount'),
            targetAmount: Payload::moneyObject($raw, 'targetAmount'),
            rate: Payload::float($raw, 'rate'),
            fees: $fees,
            creationTime: Payload::date($raw, 'creationTime'),
            raw: $raw,
        );
    }
}

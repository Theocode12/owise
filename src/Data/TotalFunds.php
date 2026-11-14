<?php

namespace Owise\Data;

use Brick\Money\Money;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise total funds overview.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TotalFunds implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new total funds instance.
     *
     * @param  list<Money>  $overdraftAvailableByCurrency
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The total worth across balances.
         *
         * @var Money|null
         */
        public ?Money $totalWorth,
        /**
         * The total amount available across balances.
         *
         * @var Money|null
         */
        public ?Money $totalAvailable,
        /**
         * The total cash across balances.
         *
         * @var Money|null
         */
        public ?Money $totalCash,
        /**
         * The overdraft limit.
         *
         * @var Money|null
         */
        public ?Money $overdraftLimit,
        /**
         * The overdraft amount used.
         *
         * @var Money|null
         */
        public ?Money $overdraftUsed,
        /**
         * The overdraft amount available.
         *
         * @var Money|null
         */
        public ?Money $overdraftAvailable,
        /**
         * The overdraft available per currency.
         *
         * @var list<Money>
         */
        public array $overdraftAvailableByCurrency = [],
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a total funds instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $availableByCurrency = [];
        foreach (Payload::list($raw, 'overdraft.availableByCurrency') as $item) {
            $money = Payload::moneyObject(['available' => $item], 'available');
            if ($money !== null) {
                $availableByCurrency[] = $money;
            }
        }

        return new self(
            totalWorth: Payload::moneyObject($raw, 'totalWorth'),
            totalAvailable: Payload::moneyObject($raw, 'totalAvailable'),
            totalCash: Payload::moneyObject($raw, 'totalCash'),
            overdraftLimit: Payload::moneyObject($raw, 'overdraft.limit'),
            overdraftUsed: Payload::moneyObject($raw, 'overdraft.used'),
            overdraftAvailable: Payload::moneyObject($raw, 'overdraft.available'),
            overdraftAvailableByCurrency: $availableByCurrency,
            raw: $raw,
        );
    }
}

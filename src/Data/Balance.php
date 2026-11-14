<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise multi-currency balance.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Balance implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new balance instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The Wise balance identifier.
         *
         * @var int
         */
        public int $id,
        /**
         * The ISO currency code held by the balance.
         *
         * @var string
         */
        public string $currency,
        /**
         * The Wise balance type.
         *
         * @var string
         */
        public string $type,
        /**
         * The user-supplied balance name.
         *
         * @var string|null
         */
        public ?string $name,
        /**
         * The total balance amount.
         *
         * @var Money|null
         */
        public ?Money $amount,
        /**
         * The amount reserved for pending operations.
         *
         * @var Money|null
         */
        public ?Money $reservedAmount,
        /**
         * The cash amount available for new operations.
         *
         * @var Money|null
         */
        public ?Money $cashAmount,
        /**
         * The total worth held in the balance.
         *
         * @var Money|null
         */
        public ?Money $totalWorth,
        /**
         * Whether the balance is visible in the dashboard.
         *
         * @var bool|null
         */
        public ?bool $visible,
        /**
         * The time the balance was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $creationTime,
        /**
         * The time the balance was last modified.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $modificationTime,
        /**
         * The investment state reported by Wise.
         *
         * @var string
         */
        public string $investmentState = 'UNKNOWN',
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a balance instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $currency = Payload::string($raw, 'currency') ?? '';

        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            currency: $currency,
            type: Payload::string($raw, 'type') ?? '',
            name: Payload::string($raw, 'name'),
            amount: Payload::moneyObject($raw, 'amount'),
            reservedAmount: Payload::moneyObject($raw, 'reservedAmount'),
            cashAmount: Payload::moneyObject($raw, 'cashAmount'),
            totalWorth: Payload::moneyObject($raw, 'totalWorth'),
            visible: Payload::bool($raw, 'visible'),
            creationTime: Payload::date($raw, 'creationTime'),
            modificationTime: Payload::date($raw, 'modificationTime'),
            investmentState: strtoupper(Payload::string($raw, 'investmentState') ?? 'UNKNOWN'),
            raw: $raw,
        );
    }

    /**
     * Determine whether the balance can be operated on via the API.
     *
     * Only `NOT_INVESTED` balances can be operated on via the API.
     * Invested balances are shown but not actionable (Wise docs).
     */
    public function isActionable(): bool
    {
        return $this->investmentState === 'NOT_INVESTED';
    }

    /**
     * Get the total balance amount, defaulting to zero.
     */
    public function amount(): Money
    {
        return $this->amount ?? Money::of(0, $this->currency);
    }

    /**
     * Get the reserved amount, defaulting to zero.
     */
    public function reserved(): Money
    {
        return $this->reservedAmount ?? Money::of(0, $this->currency);
    }

    /**
     * Get the cash amount, defaulting to zero.
     */
    public function cash(): Money
    {
        return $this->cashAmount ?? Money::of(0, $this->currency);
    }

    /**
     * Get the total worth, defaulting to zero.
     */
    public function totalWorth(): Money
    {
        return $this->totalWorth ?? Money::of(0, $this->currency);
    }
}

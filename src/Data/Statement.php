<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise balance statement.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Statement implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new statement instance.
     *
     * @param  list<array<array-key, mixed>>  $transactions  Raw statement transactions; their shape varies by type.
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The ISO currency code of the statement.
         *
         * @var string|null
         */
        public ?string $currency,
        /**
         * The start of the statement interval.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $intervalStart,
        /**
         * The end of the statement interval.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $intervalEnd,
        /**
         * The balance at the end of the statement.
         *
         * @var Money|null
         */
        public ?Money $endOfStatementBalance,
        /**
         * The raw statement transactions; their shape varies by type.
         *
         * @var list<array<array-key, mixed>>
         */
        public array $transactions,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a statement instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            currency: Payload::string($raw, 'query.currency'),
            intervalStart: Payload::date($raw, 'query.intervalStart'),
            intervalEnd: Payload::date($raw, 'query.intervalEnd'),
            endOfStatementBalance: Payload::moneyObject($raw, 'endOfStatementBalance'),
            transactions: Payload::list($raw, 'transactions'),
            raw: $raw,
        );
    }
}

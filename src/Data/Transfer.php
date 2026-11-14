<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise transfer resource.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Transfer implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new transfer instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The Wise transfer identifier.
         *
         * @var int
         */
        public int $id,
        /**
         * The transfer status reported by Wise.
         *
         * @var string
         */
        public string $status,
        /**
         * The source value of the transfer.
         *
         * @var Money|null
         */
        public ?Money $sourceValue,
        /**
         * The source ISO currency code.
         *
         * @var string|null
         */
        public ?string $sourceCurrency,
        /**
         * The target value of the transfer.
         *
         * @var Money|null
         */
        public ?Money $targetValue,
        /**
         * The target ISO currency code.
         *
         * @var string|null
         */
        public ?string $targetCurrency,
        /**
         * The exchange rate applied to the transfer.
         *
         * @var float|null
         */
        public ?float $rate,
        /**
         * The payment reference for the transfer.
         *
         * @var string|null
         */
        public ?string $reference,
        /**
         * The customer transaction identifier.
         *
         * @var string|null
         */
        public ?string $customerTransactionId,
        /**
         * The time the transfer was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $created,
        /**
         * The quote identifier used for the transfer.
         *
         * @var string|null
         */
        public ?string $quoteUuid,
        /**
         * The target recipient account identifier.
         *
         * @var int|null
         */
        public ?int $targetAccount,
        /**
         * The source balance account identifier.
         *
         * @var int|null
         */
        public ?int $sourceAccount,
        /**
         * The user identifier owning the transfer.
         *
         * @var int|null
         */
        public ?int $user,
        /**
         * The business profile identifier owning the transfer.
         *
         * @var int|null
         */
        public ?int $business,
        /**
         * Whether the transfer has active issues.
         *
         * @var bool|null
         */
        public ?bool $hasActiveIssues,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a transfer instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $sourceCurrency = Payload::string($raw, 'sourceCurrency');
        $targetCurrency = Payload::string($raw, 'targetCurrency');

        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            status: Payload::string($raw, 'status') ?? '',
            sourceValue: Payload::money($raw, 'sourceValue', $sourceCurrency),
            sourceCurrency: $sourceCurrency,
            targetValue: Payload::money($raw, 'targetValue', $targetCurrency),
            targetCurrency: $targetCurrency,
            rate: Payload::float($raw, 'rate'),
            reference: Payload::string($raw, 'details.reference') ?? Payload::string($raw, 'reference'),
            customerTransactionId: Payload::string($raw, 'customerTransactionId'),
            created: Payload::date($raw, 'created'),
            quoteUuid: Payload::string($raw, 'quoteUuid'),
            targetAccount: Payload::int($raw, 'targetAccount'),
            sourceAccount: Payload::int($raw, 'sourceAccount'),
            user: Payload::int($raw, 'user'),
            business: Payload::int($raw, 'business'),
            hasActiveIssues: Payload::bool($raw, 'hasActiveIssues'),
            raw: $raw,
        );
    }
}

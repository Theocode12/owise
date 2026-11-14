<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise recipient account.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Recipient implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new recipient instance.
     *
     * @param  string|null  $summary  Masked account summary, safe to display or persist.
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The Wise recipient identifier.
         *
         * @var int
         */
        public int $id,
        /**
         * The profile the recipient belongs to.
         *
         * @var int|null
         */
        public ?int $profileId,
        /**
         * The ISO currency code of the recipient account.
         *
         * @var string
         */
        public string $currency,
        /**
         * The country code of the recipient account.
         *
         * @var string|null
         */
        public ?string $country,
        /**
         * The Wise recipient type.
         *
         * @var string
         */
        public string $type,
        /**
         * The account holder name on the recipient.
         *
         * @var string|null
         */
        public ?string $accountHolderName,
        /**
         * The legal entity type of the recipient.
         *
         * @var string|null
         */
        public ?string $legalEntityType,
        /**
         * Whether the recipient is active.
         *
         * @var bool
         */
        public bool $active,
        /**
         * Whether the recipient is owned by the customer.
         *
         * @var bool|null
         */
        public ?bool $ownedByCustomer,
        /**
         * The masked account summary, safe to display or persist.
         *
         * @var string|null
         */
        public ?string $summary,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a recipient instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            profileId: Payload::int($raw, 'profileId') ?? Payload::int($raw, 'profile'),
            currency: Payload::string($raw, 'currency') ?? '',
            country: Payload::string($raw, 'country'),
            type: Payload::string($raw, 'type') ?? '',
            accountHolderName: Payload::string($raw, 'name.fullName') ?? Payload::string($raw, 'accountHolderName'),
            legalEntityType: Payload::string($raw, 'legalEntityType') ?? Payload::string($raw, 'details.legalType'),
            active: Payload::bool($raw, 'active') ?? true,
            ownedByCustomer: Payload::bool($raw, 'ownedByCustomer'),
            summary: Payload::string($raw, 'longAccountSummary') ?? Payload::string($raw, 'accountSummary'),
            raw: $raw,
        );
    }
}

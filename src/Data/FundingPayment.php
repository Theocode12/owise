<?php

namespace Owise\Data;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * A completed payment returned by Wise's transfer payments list endpoint.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FundingPayment implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new funding payment instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?string $method,
        public ?float $amount,
        public ?string $currency,
        public ?DateTimeImmutable $timeCreated,
        public ?DateTimeImmutable $timeUpdated,
        public array $raw = [],
    ) {}

    /**
     * Create a funding payment instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            method: Payload::string($raw, 'method'),
            amount: Payload::float($raw, 'amount'),
            currency: Payload::string($raw, 'currency'),
            timeCreated: Payload::date($raw, 'timeCreated'),
            timeUpdated: Payload::date($raw, 'timeUpdated'),
            raw: $raw,
        );
    }
}

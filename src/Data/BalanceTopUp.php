<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a sandbox balance top-up simulation result.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BalanceTopUp implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new balance top-up instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The simulated transaction identifier.
         *
         * @var int|null
         */
        public ?int $transactionId,
        /**
         * The top-up state reported by Wise.
         *
         * @var string
         */
        public string $state,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a balance top-up instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            transactionId: Payload::int($raw, 'transactionId'),
            state: Payload::string($raw, 'state') ?? '',
            raw: $raw,
        );
    }
}

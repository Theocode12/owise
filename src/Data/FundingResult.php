<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Enums\FundingStatus;
use Owise\Support\Payload;

/**
 * Represents a Wise transfer funding response.
 *
 * Wise reports some funding failures (e.g. insufficient funds) as a successful response with status REJECTED.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FundingResult implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new funding result instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The funding type used for the transfer.
         *
         * @var string|null
         */
        public ?string $type,
        /**
         * The funding status reported by Wise.
         *
         * @var string
         */
        public string $status,
        /**
         * The Wise error code for rejected funding.
         *
         * @var string|null
         */
        public ?string $errorCode,
        /**
         * The Wise error message for rejected funding.
         *
         * @var string|null
         */
        public ?string $errorMessage,
        /**
         * The balance transaction created by the funding.
         *
         * @var int|null
         */
        public ?int $balanceTransactionId,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a funding result instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            type: Payload::string($raw, 'type'),
            status: Payload::string($raw, 'status') ?? '',
            errorCode: Payload::string($raw, 'errorCode'),
            errorMessage: Payload::string($raw, 'errorMessage'),
            balanceTransactionId: Payload::int($raw, 'balanceTransactionId'),
            raw: $raw,
        );
    }

    /**
     * Determine whether the funding completed.
     */
    public function isCompleted(): bool
    {
        return FundingStatus::tryFrom(strtoupper($this->status)) === FundingStatus::Completed;
    }

    /**
     * Determine whether the funding was rejected.
     */
    public function isRejected(): bool
    {
        return FundingStatus::tryFrom(strtoupper($this->status)) === FundingStatus::Rejected;
    }
}

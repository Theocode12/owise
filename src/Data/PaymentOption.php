<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise quote payment option.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PaymentOption implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new payment option instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The pay-in method for the option.
         *
         * @var string|null
         */
        public ?string $payIn,
        /**
         * The pay-out method for the option.
         *
         * @var string|null
         */
        public ?string $payOut,
        /**
         * Whether the option is currently disabled.
         *
         * @var bool
         */
        public bool $disabled,
        /**
         * The reason the option is disabled.
         *
         * @var string|null
         */
        public ?string $disabledReason,
        /**
         * The source amount for the option.
         *
         * @var Money|null
         */
        public ?Money $sourceAmount,
        /**
         * The target amount for the option.
         *
         * @var Money|null
         */
        public ?Money $targetAmount,
        /**
         * The fee charged for the option.
         *
         * @var Money|null
         */
        public ?Money $fee,
        /**
         * The estimated delivery time for the option.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $estimatedDelivery,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a payment option instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $sourceCurrency = Payload::string($raw, 'sourceCurrency');

        return new self(
            payIn: Payload::string($raw, 'payIn'),
            payOut: Payload::string($raw, 'payOut'),
            disabled: Payload::bool($raw, 'disabled') ?? false,
            disabledReason: Payload::string($raw, 'disabledReason.message') ?? Payload::string($raw, 'disabledReason.code'),
            sourceAmount: Payload::money($raw, 'sourceAmount', $sourceCurrency),
            targetAmount: Payload::money($raw, 'targetAmount', Payload::string($raw, 'targetCurrency')),
            fee: Payload::money($raw, 'fee.total', $sourceCurrency),
            estimatedDelivery: Payload::date($raw, 'estimatedDelivery'),
            raw: $raw,
        );
    }
}

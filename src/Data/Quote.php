<?php

namespace Owise\Data;

use Brick\Money\Money;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise quote resource.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Quote implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new quote instance.
     *
     * @param  list<PaymentOption>  $paymentOptions
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The Wise quote identifier.
         *
         * @var string
         */
        public string $id,
        /**
         * The profile the quote belongs to.
         *
         * @var int|null
         */
        public ?int $profileId,
        /**
         * The source ISO currency code.
         *
         * @var string
         */
        public string $sourceCurrency,
        /**
         * The target ISO currency code.
         *
         * @var string
         */
        public string $targetCurrency,
        /**
         * The source amount quoted.
         *
         * @var Money|null
         */
        public ?Money $sourceAmount,
        /**
         * The target amount quoted.
         *
         * @var Money|null
         */
        public ?Money $targetAmount,
        /**
         * The exchange rate quoted.
         *
         * @var float|null
         */
        public ?float $rate,
        /**
         * The rate type quoted by Wise.
         *
         * @var string|null
         */
        public ?string $rateType,
        /**
         * The pay-out method for the quote.
         *
         * @var string|null
         */
        public ?string $payOut,
        /**
         * The quote status reported by Wise.
         *
         * @var string|null
         */
        public ?string $status,
        /**
         * The time the quote was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $createdTime,
        /**
         * The time the quoted rate expires.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $rateExpirationTime,
        /**
         * The time the quote expires.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $expirationTime,
        /**
         * The payment options available for the quote.
         *
         * @var list<PaymentOption>
         */
        public array $paymentOptions,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
        /**
         * The preferred pay-in method for the quote.
         *
         * @var string|null
         */
        public ?string $preferredPayIn = null,
    ) {}

    /**
     * Create a quote instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $sourceCurrency = Payload::string($raw, 'sourceCurrency') ?? '';
        $targetCurrency = Payload::string($raw, 'targetCurrency') ?? '';

        return new self(
            id: Payload::string($raw, 'id') ?? '',
            profileId: Payload::int($raw, 'profile'),
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: Payload::money($raw, 'sourceAmount', $sourceCurrency ?: null),
            targetAmount: Payload::money($raw, 'targetAmount', $targetCurrency ?: null),
            rate: Payload::float($raw, 'rate'),
            rateType: Payload::string($raw, 'rateType'),
            payOut: Payload::string($raw, 'payOut'),
            preferredPayIn: Payload::string($raw, 'preferredPayIn'),
            status: Payload::string($raw, 'status'),
            createdTime: Payload::date($raw, 'createdTime'),
            rateExpirationTime: Payload::date($raw, 'rateExpirationTime'),
            expirationTime: Payload::date($raw, 'expirationTime'),
            paymentOptions: array_map(PaymentOption::fromArray(...), Payload::list($raw, 'paymentOptions')),
            raw: $raw,
        );
    }

    /**
     * Get the payment option matching the given pay-in and pay-out methods.
     */
    public function paymentOption(string $payIn, ?string $payOut = null): ?PaymentOption
    {
        foreach ($this->paymentOptions as $option) {
            if ($option->payIn === $payIn && $option->payOut === ($payOut ?? $this->payOut)) {
                return $option;
            }
        }

        return null;
    }
}

<?php

namespace Owise\Data;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise webhook subscription.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WebhookSubscription implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new webhook subscription instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The subscription identifier.
         *
         * @var string
         */
        public string $id,
        /**
         * The subscription name.
         *
         * @var string
         */
        public string $name,
        /**
         * The event the subscription triggers on.
         *
         * @var string
         */
        public string $triggerOn,
        /**
         * The delivery version for the subscription.
         *
         * @var string|null
         */
        public ?string $deliveryVersion,
        /**
         * The delivery URL for webhook events.
         *
         * @var string|null
         */
        public ?string $deliveryUrl,
        /**
         * Whether mutual TLS delivery is enabled.
         *
         * @var bool|null
         */
        public ?bool $deliveryMtlsEnabled,
        /**
         * The scope domain of the subscription.
         *
         * @var string|null
         */
        public ?string $scopeDomain,
        /**
         * The scope identifier of the subscription.
         *
         * @var string|null
         */
        public ?string $scopeId,
        /**
         * The time the subscription was created.
         *
         * @var DateTimeImmutable|null
         */
        public ?DateTimeImmutable $createdAt,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a webhook subscription instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: Payload::string($raw, 'id') ?? '',
            name: Payload::string($raw, 'name') ?? '',
            triggerOn: Payload::string($raw, 'trigger_on') ?? '',
            deliveryVersion: Payload::string($raw, 'delivery.version'),
            deliveryUrl: Payload::string($raw, 'delivery.url'),
            deliveryMtlsEnabled: Payload::bool($raw, 'delivery.mtls_enabled'),
            scopeDomain: Payload::string($raw, 'scope.domain'),
            scopeId: Payload::string($raw, 'scope.id'),
            createdAt: Payload::date($raw, 'created_at'),
            raw: $raw,
        );
    }
}

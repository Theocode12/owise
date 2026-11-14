<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise profile resource.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Profile implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new profile instance.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The Wise profile identifier.
         *
         * @var int
         */
        public int $id,
        /**
         * The Wise profile type.
         *
         * @var string
         */
        public string $type,
        /**
         * The full or business name on the profile.
         *
         * @var string|null
         */
        public ?string $fullName,
        /**
         * The public identifier for the profile.
         *
         * @var string|null
         */
        public ?string $publicId,
        /**
         * The contact email on the profile.
         *
         * @var string|null
         */
        public ?string $email,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a profile instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: Payload::int($raw, 'id') ?? 0,
            type: Payload::string($raw, 'type') ?? '',
            fullName: Payload::string($raw, 'fullName') ?? Payload::string($raw, 'businessName'),
            publicId: Payload::string($raw, 'publicId'),
            email: Payload::string($raw, 'email'),
            raw: $raw,
        );
    }

    /**
     * Determine whether the profile is a business profile.
     */
    public function isBusiness(): bool
    {
        return strtoupper($this->type) === 'BUSINESS';
    }
}

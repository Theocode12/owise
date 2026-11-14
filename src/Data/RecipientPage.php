<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a paginated Wise recipient list.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RecipientPage implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new recipient page instance.
     *
     * @param  list<Recipient>  $recipients
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The recipients on the current page.
         *
         * @var list<Recipient>
         */
        public array $recipients,
        /**
         * The seek position for the next page.
         *
         * @var int|null
         */
        public ?int $nextSeekPosition,
        /**
         * The number of recipients on the page.
         *
         * @var int|null
         */
        public ?int $size,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a recipient page instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            recipients: array_map(Recipient::fromArray(...), Payload::list($raw, 'content')),
            nextSeekPosition: Payload::int($raw, 'seekPositionForNext'),
            size: Payload::int($raw, 'size'),
            raw: $raw,
        );
    }

    /**
     * Determine whether another page of recipients is available.
     */
    public function hasMore(): bool
    {
        return $this->nextSeekPosition !== null;
    }
}

<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents one input of a Wise dynamic form.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Field implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new field instance.
     *
     * @param  list<array{key: string, name: string}>|null  $valuesAllowed
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The field key used when submitting the form.
         *
         * @var string
         */
        public string $key,
        /**
         * The display name of the field.
         *
         * @var string|null
         */
        public ?string $name,
        /**
         * The group the field belongs to.
         *
         * @var string|null
         */
        public ?string $group,
        /**
         * The Wise field input type.
         *
         * @var string
         */
        public string $type,
        /**
         * Whether the field must be supplied.
         *
         * @var bool
         */
        public bool $required,
        /**
         * Whether changing the field refreshes the requirements.
         *
         * @var bool
         */
        public bool $refreshRequirementsOnChange,
        /**
         * The validation pattern supplied by Wise.
         *
         * @var string|null
         */
        public ?string $validationRegexp,
        /**
         * The minimum accepted length.
         *
         * @var int|null
         */
        public ?int $minLength,
        /**
         * The maximum accepted length.
         *
         * @var int|null
         */
        public ?int $maxLength,
        /**
         * The example value supplied by Wise.
         *
         * @var string|null
         */
        public ?string $example,
        /**
         * The allowed key/name pairs for select fields.
         *
         * @var list<array{key: string, name: string}>|null
         */
        public ?array $valuesAllowed,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a field instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw, ?string $group = null): self
    {
        $valuesAllowed = null;
        if (is_array($raw['valuesAllowed'] ?? null)) {
            $valuesAllowed = [];
            foreach (Payload::arrays($raw['valuesAllowed']) as $value) {
                $valuesAllowed[] = [
                    'key' => Payload::string($value, 'key') ?? '',
                    'name' => Payload::string($value, 'name') ?? '',
                ];
            }
        }

        return new self(
            key: Payload::string($raw, 'key') ?? '',
            name: Payload::string($raw, 'name'),
            group: $group,
            type: Payload::string($raw, 'type') ?? 'text',
            required: Payload::bool($raw, 'required') ?? false,
            refreshRequirementsOnChange: Payload::bool($raw, 'refreshRequirementsOnChange') ?? false,
            validationRegexp: Payload::string($raw, 'validationRegexp'),
            minLength: Payload::int($raw, 'minLength'),
            maxLength: Payload::int($raw, 'maxLength'),
            example: Payload::string($raw, 'example'),
            valuesAllowed: $valuesAllowed,
            raw: $raw,
        );
    }
}

<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents one Wise requirement type and its fields.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RequirementType implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new requirement type instance.
     *
     * @param  list<Field>  $fields  Flattened from Wise's `fields[].group[]`.
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The requirement type name.
         *
         * @var string
         */
        public string $type,
        /**
         * The display title of the type.
         *
         * @var string|null
         */
        public ?string $title,
        /**
         * The usage information for the type.
         *
         * @var string|null
         */
        public ?string $usageInfo,
        /**
         * The flattened fields for the type.
         *
         * Flattened from Wise's `fields[].group[]`.
         *
         * @var list<Field>
         */
        public array $fields,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a requirement type instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        $fields = [];
        foreach (Payload::list($raw, 'fields') as $fieldGroup) {
            $groupName = Payload::string($fieldGroup, 'name');
            foreach (Payload::list($fieldGroup, 'group') as $field) {
                $fields[] = Field::fromArray($field, $groupName);
            }
        }

        return new self(
            type: Payload::string($raw, 'type') ?? '',
            title: Payload::string($raw, 'title'),
            usageInfo: Payload::string($raw, 'usageInfo'),
            fields: $fields,
            raw: $raw,
        );
    }

    /**
     * Get the field matching the given key.
     */
    public function field(string $key): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get the fields that refresh requirements on change.
     *
     * @return list<Field>
     */
    public function refreshableFields(): array
    {
        return array_values(array_filter($this->fields, fn (Field $field): bool => $field->refreshRequirementsOnChange));
    }
}

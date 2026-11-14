<?php

namespace Owise\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonSerializable;
use Owise\Concerns\SerializesData;
use Owise\Support\Payload;

/**
 * Represents a Wise dynamic requirements form.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RequirementsForm implements Arrayable, JsonSerializable
{
    use SerializesData;

    /**
     * Create a new requirements form instance.
     *
     * @param  list<RequirementType>  $types
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        /**
         * The requirement types in the form.
         *
         * @var list<RequirementType>
         */
        public array $types,
        /**
         * The raw Wise API payload.
         *
         * @var array<array-key, mixed>
         */
        public array $raw = [],
    ) {}

    /**
     * Create a requirements form instance from a Wise API payload.
     *
     * @param  array<array-key, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            types: array_map(RequirementType::fromArray(...), Payload::arrays($raw)),
            raw: $raw,
        );
    }

    /**
     * Get the requirement type matching the given type.
     */
    public function type(string $type): ?RequirementType
    {
        foreach ($this->types as $requirementType) {
            if ($requirementType->type === $type) {
                return $requirementType;
            }
        }

        return null;
    }

    /**
     * Get the requirement type names in the form.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(
            static fn (RequirementType $type): string => $type->type,
            $this->types,
        );
    }

    /**
     * Get the fields for the given requirement type.
     *
     * @return list<Field>
     */
    public function fields(string $type): array
    {
        $requirementType = $this->type($type);

        return $requirementType === null ? [] : $requirementType->fields;
    }

    /**
     * Convert the form constraints into Laravel validation rules.
     *
     * `accountHolderName` is a top-level create-recipient field; other keys belong to `details`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $type): array
    {
        $rules = [];

        foreach ($this->fields($type) as $field) {
            $key = str_replace('/', '.', $field->key);
            $attribute = $key === 'accountHolderName' || str_starts_with($key, 'details.')
                ? $key
                : 'details.'.$key;
            if (str_starts_with($attribute, 'details.details.')) {
                $attribute = substr($attribute, strlen('details.'));
            }

            $fieldRules = $field->required ? ['required'] : ['sometimes', 'nullable'];
            $fieldRules[] = 'string';

            if ($field->minLength !== null) {
                $fieldRules[] = 'min:'.$field->minLength;
            }

            if ($field->maxLength !== null) {
                $fieldRules[] = 'max:'.$field->maxLength;
            }

            if ($field->validationRegexp !== null && $field->validationRegexp !== '') {
                $fieldRules[] = $this->regexRule($field->validationRegexp);
            }

            $allowedValues = array_values(array_filter(
                array_map(static fn (array $value): string => $value['key'], $field->valuesAllowed ?? []),
                static fn (string $value): bool => $value !== '',
            ));
            if ($allowedValues !== []) {
                $fieldRules[] = Rule::in($allowedValues);
            }

            $rules[$attribute] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Determine whether changing the given key refreshes the requirements.
     */
    public function needsRefresh(string $key): bool
    {
        foreach ($this->types as $type) {
            if ($type->field($key)?->refreshRequirementsOnChange === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a Laravel regex rule from a Wise validation pattern.
     *
     *
     * @throws InvalidArgumentException
     */
    private function regexRule(string $pattern): string
    {
        $delimiter = null;
        foreach (['~', '#', '%', '!', ';', '@'] as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                $delimiter = $candidate;
                break;
            }
        }

        if ($delimiter === null) {
            throw new InvalidArgumentException('Wise supplied a validation pattern with no safe regex delimiter.');
        }

        $regex = $delimiter.$pattern.$delimiter.'u';
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException('Wise supplied an invalid validationRegexp in the requirements form.');
        }

        return 'regex:'.$regex;
    }
}

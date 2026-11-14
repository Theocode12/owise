<?php

namespace Owise\Data;

use InvalidArgumentException;

/**
 * Validated options for an authenticated Wise quote request.
 *
 * Keeps Wise's quote request schema out of payout orchestration while allowing both the payout
 * builder and QuotesApi to normalize the same options consistently.
 */
final readonly class QuoteOptions
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /**
     * Build quote options from caller-supplied values.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $values): self
    {
        $allowed = [
            'preferredPayIn',
            'payInMethod',
            'payInId',
            'preferredPayInId',
            'paymentMetadata',
            'creationContext',
            'pricingConfiguration',
        ];
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unsupported quote option(s): '.implode(', ', $unknown).'.');
        }

        foreach (['preferredPayIn', 'payInMethod'] as $key) {
            if (isset($values[$key]) && (! is_string($values[$key]) || trim($values[$key]) === '')) {
                throw new InvalidArgumentException("Quote option {$key} must be a non-empty string.");
            }
        }

        foreach (['payInId', 'preferredPayInId'] as $key) {
            if (isset($values[$key]) && ! is_int($values[$key]) && ! is_string($values[$key])) {
                throw new InvalidArgumentException("Quote option {$key} must be an integer or string.");
            }
        }

        foreach (['paymentMetadata', 'creationContext', 'pricingConfiguration'] as $key) {
            if (isset($values[$key]) && ! is_array($values[$key])) {
                throw new InvalidArgumentException("Quote option {$key} must be an array.");
            }
        }

        if (($values['paymentMetadata'] ?? []) === []) {
            unset($values['paymentMetadata']);
        }

        return new self($values);
    }

    /**
     * Add the payout builder's transfer-nature convenience value to payment metadata.
     *
     * @throws InvalidArgumentException
     */
    public function withTransferNature(?string $transferNature): self
    {
        if ($transferNature === null) {
            return $this;
        }

        $metadata = $this->values['paymentMetadata'] ?? [];
        if (isset($metadata['transferNature']) && $metadata['transferNature'] !== $transferNature) {
            throw new InvalidArgumentException('transferNature conflicts with paymentMetadata.transferNature.');
        }

        $metadata['transferNature'] = $transferNature;

        return new self([...$this->values, 'paymentMetadata' => $metadata]);
    }

    /**
     * Supply the payout-mode default without overriding a caller's explicit preference.
     */
    public function withDefaultPreferredPayIn(string $preferredPayIn): self
    {
        if ($this->preferredPayIn() !== null) {
            return $this;
        }

        return new self([...$this->values, 'preferredPayIn' => $preferredPayIn]);
    }

    /**
     * Get the configured preferred pay-in method.
     */
    public function preferredPayIn(): ?string
    {
        $preferredPayIn = $this->values['preferredPayIn'] ?? null;

        return is_string($preferredPayIn) ? $preferredPayIn : null;
    }

    /**
     * Return normalized quote options.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includePreferredPayIn = true): array
    {
        $values = array_filter($this->values, static fn (mixed $value): bool => $value !== null);

        if (! $includePreferredPayIn) {
            unset($values['preferredPayIn']);
        }

        return $values;
    }
}

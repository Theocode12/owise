<?php

namespace Owise\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Owise\Data\Recipient;
use Owise\Data\Recipient as WiseRecipientData;
use Owise\Data\RequirementsForm;
use Owise\Events\RecipientCreated;
use Owise\Events\RecipientRemoved;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\InvalidRecipientDetails;
use Owise\Exceptions\RecipientOwnershipMismatch;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Models\WiseRecipient;
use Owise\Support\WiseEventDispatcher;
use Owise\WiseManager;
use Owise\WiseProfile;

/**
 * Manage the Wise recipients belonging to a payable model.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait ManagesRecipients
{
    /**
     * Resolve the Wise profile for the payable model.
     */
    public function wiseProfile(): WiseProfile
    {
        return app(WiseManager::class)->profile();
    }

    /**
     * Get the default recipient holder name for the model.
     */
    public function wiseRecipientName(): ?string
    {
        $name = $this->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Get the default recipient email for the model.
     */
    public function wiseRecipientEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Get the default legal entity type for new recipients.
     */
    public function wiseRecipientLegalEntityType(): ?string
    {
        return 'PRIVATE';
    }

    /**
     * Get the Wise recipients belonging to the model.
     *
     * @return MorphMany<WiseRecipient, $this>
     */
    public function wiseRecipients(): MorphMany
    {
        return $this->morphMany($this->wiseRecipientModelClass(), 'payable');
    }

    /**
     * Get the default Wise recipient, optionally for a currency.
     */
    public function defaultWiseRecipient(?string $currency = null): ?WiseRecipient
    {
        $query = $this->wiseRecipients()->active()->where('is_default', true);
        if ($currency !== null) {
            $query->forCurrency($currency);
        }

        return $query->first();
    }

    /**
     * Determine if the model has an active Wise recipient.
     */
    public function hasWiseRecipient(?string $currency = null): bool
    {
        $query = $this->wiseRecipients()->active();
        if ($currency !== null) {
            $query->forCurrency($currency);
        }

        return $query->exists();
    }

    /**
     * Get the recipient requirements for a quote.
     */
    public function wiseRecipientRequirements(string $quoteId): RequirementsForm
    {
        return $this->wiseProfile()->recipients()->requirements($quoteId);
    }

    /**
     * Refresh the recipient requirements for a quote.
     *
     * @param  array<string, mixed>  $recipientPayload
     */
    public function refreshWiseRecipientRequirements(string $quoteId, array $recipientPayload): RequirementsForm
    {
        $profile = $this->wiseProfile();
        $recipientPayload['profile'] = $profile->profileId();

        return $profile->recipients()->refreshRequirements($quoteId, $recipientPayload);
    }

    /**
     * Create a Wise recipient and attach it to the model.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws InvalidArgumentException
     * @throws InvalidRecipientDetails
     * @throws WiseException
     */
    public function addWiseRecipient(
        string $currency,
        string $type,
        array $details,
        ?string $holderName = null,
        bool $default = false,
        bool $ownedByCustomer = false,
        ?RequirementsForm $requirements = null,
    ): WiseRecipient {
        $this->assertPayableIsPersisted();

        $profile = $this->wiseProfile();
        $profileId = $profile->profileId();
        $details = $this->withRequirementDefaults($type, $details, $requirements);

        $holderName ??= $requirements === null || $requirements->type($type)?->field('accountHolderName') !== null
            ? $this->wiseRecipientName()
            : null;

        $payload = [
            'profile' => $profileId,
            'currency' => strtoupper($currency),
            'type' => $type,
            'accountHolderName' => $holderName,
            'ownedByCustomer' => $ownedByCustomer,
            'details' => $details,
        ];

        if ($requirements !== null) {
            if ($requirements->type($type) === null) {
                throw new InvalidArgumentException("The recipient requirements do not contain type '{$type}'.");
            }

            Validator::make(
                ['accountHolderName' => $holderName, 'details' => $details],
                $requirements->rules($type),
            )->validate();
        }

        try {
            $remote = $profile->recipients()->create($payload);
        } catch (WiseApiError $exception) {
            if ($exception->status !== 422) {
                throw $exception;
            }

            throw new InvalidRecipientDetails($this->fieldErrors($exception), $exception);
        }

        $remoteProfileId = $remote->profileId ?? $profileId;
        if ($remoteProfileId !== $profileId) {
            throw new WiseException('Wise created a recipient under a different profile than requested.');
        }

        $recipient = $this->storeWiseRecipient($remote, $profile, $default);
        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new RecipientCreated($recipient));

        return $recipient;
    }

    /**
     * Attach an existing Wise recipient to the model.
     *
     *
     * @throws InvalidArgumentException
     * @throws RecipientOwnershipMismatch
     */
    public function attachWiseRecipient(int $wiseRecipientId): WiseRecipient
    {
        $this->assertPayableIsPersisted();

        $profile = $this->wiseProfile();
        $remote = $profile->recipients()->find($wiseRecipientId);
        $profileId = $profile->profileId();

        if ($remote->profileId !== null && $remote->profileId !== $profileId) {
            throw new RecipientOwnershipMismatch('The Wise recipient belongs to a different Wise profile.');
        }

        $recipient = $this->storeWiseRecipient($remote, $profile, false);
        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new RecipientCreated($recipient));

        return $recipient;
    }

    /**
     * Remove a Wise recipient from the model.
     *
     * Active remote recipients are deactivated before the local record is removed.
     *
     *
     * @throws RecipientOwnershipMismatch
     */
    public function removeWiseRecipient(WiseRecipient $recipient): WiseRecipient
    {
        $this->assertPayableIsPersisted();
        $this->assertRecipientOwner($recipient);

        if ($recipient->active) {
            $recipient->profile()->recipients()->delete($recipient->wise_recipient_id);
            $recipient->active = false;
        }

        $recipient->is_default = false;
        $recipient->save();
        $recipient->delete();
        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new RecipientRemoved($recipient));

        return $recipient;
    }

    /**
     * Mark active local recipients missing from Wise's active recipient list as inactive.
     *
     * Remote-only Wise recipients are not imported because they cannot be assigned to a payable safely.
     *
     * @return int Number of local rows marked inactive.
     *
     * @throws InvalidArgumentException
     * @throws WiseException
     */
    public function syncWiseRecipients(): int
    {
        $this->assertPayableIsPersisted();

        $profile = $this->wiseProfile();
        $profileId = $profile->profileId();
        $remoteIds = [];
        $seekPosition = null;
        $seenPositions = [];

        do {
            $page = $profile->recipients()->list(
                active: true,
                seekPosition: $seekPosition,
                size: 20,
                profileId: $profileId,
            );

            foreach ($page->recipients as $remote) {
                $remoteIds[$remote->id] = true;
            }

            $nextSeekPosition = $page->nextSeekPosition;
            if ($nextSeekPosition !== null && isset($seenPositions[$nextSeekPosition])) {
                throw new WiseException('Wise recipient pagination returned a repeated seek position.');
            }
            if ($nextSeekPosition !== null) {
                $seenPositions[$nextSeekPosition] = true;
            }

            $seekPosition = $nextSeekPosition;
        } while ($seekPosition !== null);

        $markedInactive = 0;
        foreach ($this->wiseRecipients()->where('profile_id', $profileId)->where('active', true)->get() as $local) {
            if (! isset($remoteIds[$local->wise_recipient_id])) {
                $local->forceFill(['active' => false, 'is_default' => false])->save();
                $markedInactive++;
            }
        }

        return $markedInactive;
    }

    /**
     * Resolve the Wise recipient model class.
     *
     * @return class-string<WiseRecipient>
     *
     * @throws InvalidConfiguration
     */
    private function wiseRecipientModelClass(): string
    {
        $class = config('wise.models.recipient', WiseRecipient::class);
        if (! is_string($class) || ! is_a($class, WiseRecipient::class, true)) {
            throw new InvalidConfiguration('wise.models.recipient must be a WiseRecipient model class.');
        }

        return $class;
    }

    /**
     * Assert that the payable model has been persisted.
     *
     *
     * @throws InvalidArgumentException
     */
    private function assertPayableIsPersisted(): void
    {
        if (! $this->exists || $this->getKey() === null) {
            throw new InvalidArgumentException('Persist the payable model before managing Wise recipients.');
        }
    }

    /**
     * Merge model defaults into the recipient details.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function withRequirementDefaults(
        string $type,
        array $details,
        ?RequirementsForm $requirements,
    ): array {
        if ($requirements === null || $requirements->type($type) === null) {
            return $details;
        }

        $fields = $requirements->fields($type);
        foreach ($fields as $field) {
            if ($field->key === 'email' && ! array_key_exists('email', $details)) {
                $email = $this->wiseRecipientEmail();
                if ($email !== null) {
                    $details['email'] = $email;
                }
            }

            if ($field->key === 'legalType' && ! array_key_exists('legalType', $details)) {
                $legalEntityType = $this->wiseRecipientLegalEntityType();
                if ($legalEntityType !== null) {
                    $details['legalType'] = $legalEntityType;
                }
            }

        }

        return $details;
    }

    /**
     * Store a remote Wise recipient on the payable model.
     */
    private function storeWiseRecipient(WiseRecipientData $remote, WiseProfile $profile, bool $default): WiseRecipient
    {
        $profileId = $remote->profileId ?? $profile->profileId();
        $modelClass = $this->wiseRecipientModelClass();

        return DB::transaction(function () use ($remote, $profile, $profileId, $modelClass, $default): WiseRecipient {
            /** @var WiseRecipient|null $recipient */
            $recipient = $modelClass::withTrashed()
                ->where('profile_id', $profileId)
                ->where('wise_recipient_id', $remote->id)
                ->first();

            if ($recipient !== null) {
                $this->assertRecipientOwner($recipient);
                if ($recipient->trashed()) {
                    $recipient->restore();
                }
            } else {
                $recipient = $this->wiseRecipients()->make();
            }

            if ($default) {
                $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->first();
                $this->wiseRecipients()
                    ->where('currency', strtoupper($remote->currency))
                    ->where('profile_id', $profileId)
                    ->update(['is_default' => false]);
            }

            $recipient->fill([
                'profile_name' => $profile->context()->name,
                'profile_id' => $profileId,
                'wise_recipient_id' => $remote->id,
                'currency' => strtoupper($remote->currency),
                'type' => Str::snake($remote->type),
                'holder_name' => $remote->accountHolderName,
                'summary' => $this->safeSummary($remote),
                'country' => $remote->country,
                'is_default' => $default || $recipient->is_default,
                'active' => $remote->active,
                'meta' => $remote->legalEntityType === null ? [] : ['legal_entity_type' => $remote->legalEntityType],
            ]);
            $recipient->save();

            return $recipient;
        });
    }

    /**
     * Assert that the recipient belongs to the payable model.
     *
     *
     * @throws RecipientOwnershipMismatch
     */
    private function assertRecipientOwner(WiseRecipient $recipient): void
    {
        if ($recipient->payable_type !== $this->getMorphClass() || (string) $recipient->payable_id !== (string) $this->getKey()) {
            throw new RecipientOwnershipMismatch;
        }
    }

    /**
     * Extract field errors from a Wise API error.
     *
     * @return array<string, list<string>>
     */
    private function fieldErrors(WiseApiError $exception): array
    {
        $fieldErrors = [];
        foreach ($exception->errors as $error) {
            $path = $error['path'];
            $key = $path === null || $path === '' ? 'recipient' : trim(str_replace('/', '.', $path), '.');
            if (! in_array($key, ['recipient', 'accountHolderName', 'profile', 'currency', 'type', 'ownedByCustomer', 'details'], true)
                && ! str_starts_with($key, 'details.')) {
                $key = 'details.'.$key;
            }
            $fieldErrors[$key][] = $error['message'] ?? $exception->getMessage();
        }

        if ($fieldErrors === []) {
            $fieldErrors['recipient'] = [$exception->getMessage()];
        }

        return $fieldErrors;
    }

    /**
     * Build a safe display summary without sensitive identifiers.
     */
    private function safeSummary(WiseRecipientData $remote): string
    {
        $candidate = $remote->raw['longAccountSummary'] ?? null;
        $summary = is_string($candidate) ? $candidate : null;
        $identifiers = $this->recipientIdentifiers($remote->raw);
        $normalizedSummary = $this->normalizeIdentifier($summary ?? '');

        if ($summary !== null && (
            preg_match('/\d{5,}/', $summary) === 1
            || preg_match('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,}\b/i', $summary) === 1
        )) {
            $summary = null;
        }

        foreach ([...$identifiers['account'], ...$identifiers['bank'], ...$identifiers['other']] as $identifier) {
            $normalizedIdentifier = $this->normalizeIdentifier($identifier);
            if (strlen($normalizedIdentifier) >= 4 && str_contains($normalizedSummary, $normalizedIdentifier)) {
                $summary = null;
                break;
            }
        }

        if ($summary !== null && trim($summary) !== '') {
            return $summary;
        }

        $lastFour = null;
        foreach ($identifiers['account'] as $identifier) {
            $normalizedIdentifier = $this->normalizeIdentifier($identifier);
            if (strlen($normalizedIdentifier) > 4) {
                $lastFour = substr($normalizedIdentifier, -4);
                break;
            }
        }

        $label = strtoupper($remote->currency).' '.strtoupper($remote->type);

        return $lastFour === null ? $label.' recipient' : $label.' •••• '.$lastFour;
    }

    /**
     * Collect account identifiers from raw recipient data.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array{account: list<string>, bank: list<string>, other: list<string>}
     */
    private function recipientIdentifiers(array $raw): array
    {
        $identifiers = ['account' => [], 'bank' => [], 'other' => []];
        $collect = function (mixed $value, ?string $key = null) use (&$collect, &$identifiers): void {
            if (is_string($value) && trim($value) !== '') {
                if ($key !== null && preg_match('/iban|account(number|identifier|id)?/i', $key) === 1) {
                    $identifiers['account'][] = $value;
                } elseif ($key !== null && preg_match('/routing|sort.?code|swift|bic/i', $key) === 1) {
                    $identifiers['bank'][] = $value;
                } else {
                    $identifiers['other'][] = $value;
                }
            } elseif (is_array($value)) {
                foreach ($value as $nestedKey => $nested) {
                    $collect($nested, is_string($nestedKey) ? $nestedKey : $key);
                }
            }
        };

        $collect($raw['details'] ?? []);
        $collect($raw['name'] ?? []);
        foreach ($raw['displayFields'] ?? [] as $displayField) {
            if (is_array($displayField)) {
                $fieldKey = is_string($displayField['key'] ?? null)
                    ? $displayField['key']
                    : (is_string($displayField['label'] ?? null) ? $displayField['label'] : null);
                $collect($displayField['value'] ?? null, $fieldKey);
            }
        }

        return [
            'account' => array_values(array_unique($identifiers['account'])),
            'bank' => array_values(array_unique($identifiers['bank'])),
            'other' => array_values(array_unique($identifiers['other'])),
        ];
    }

    /**
     * Normalize an identifier for safe comparison.
     */
    private function normalizeIdentifier(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}

<?php

namespace Owise\Api;

use Owise\Data\Recipient;
use Owise\Data\RecipientPage;
use Owise\Data\RequirementsForm;
use Owise\Exceptions\MissingProfile;
use Owise\Exceptions\WiseApiError;

/**
 * Manage Wise recipients and account requirements for the current profile.
 */
final class RecipientsApi extends AccountApi
{
    /** The headers required for account-requirements calls. @var array<string, string> */
    private const REQUIREMENTS_HEADERS = ['Accept-Minor-Version' => '1'];

    /**
     * Get the recipient requirements for a quote.
     *
     * @param  string  $quoteId  The quote ID.
     * @param  string|null  $originatorLegalEntityType  The originator legal entity type, if any.
     * @param  bool  $addressRequired  Whether the recipient address is required.
     * @return RequirementsForm The requirements form.
     *
     * @throws WiseApiError
     */
    public function requirements(
        string $quoteId,
        ?string $originatorLegalEntityType = null,
        bool $addressRequired = false,
    ): RequirementsForm {
        return RequirementsForm::fromArray($this->client->requestOperation(
            'recipients.requirements.get',
            ['quoteId' => $quoteId],
            query: $this->requirementsQuery($originatorLegalEntityType, $addressRequired),
            headers: self::REQUIREMENTS_HEADERS,
        ));
    }

    /**
     * Refresh the recipient requirements for a quote.
     *
     * Re-fetch requirements after a field with `refreshRequirementsOnChange` changed. `$payload` is the
     * recipient payload as it would be sent to create().
     *
     * @param  string  $quoteId  The quote ID.
     * @param  array<string, mixed>  $payload  The recipient payload.
     * @param  string|null  $originatorLegalEntityType  The originator legal entity type, if any.
     * @return RequirementsForm The requirements form.
     *
     * @throws WiseApiError
     */
    public function refreshRequirements(string $quoteId, array $payload, ?string $originatorLegalEntityType = null): RequirementsForm
    {
        return RequirementsForm::fromArray($this->client->requestOperation(
            'recipients.requirements.refresh',
            ['quoteId' => $quoteId],
            query: $this->requirementsQuery($originatorLegalEntityType, false),
            body: $this->withProfile($payload),
            headers: self::REQUIREMENTS_HEADERS,
        ));
    }

    /**
     * Create a recipient for the current profile.
     *
     * @param  array<string, mixed>  $payload  The recipient payload.
     * @return Recipient The created recipient.
     *
     * @throws WiseApiError
     */
    public function create(array $payload): Recipient
    {
        return Recipient::fromArray($this->client->requestOperation('recipients.create', body: $this->withProfile($payload)));
    }

    /**
     * Get a recipient by its ID.
     *
     * @param  int  $recipientId  The recipient ID.
     * @return Recipient The recipient.
     *
     * @throws WiseApiError
     */
    public function find(int $recipientId): Recipient
    {
        return Recipient::fromArray($this->client->requestOperation('recipients.find', ['recipientId' => $recipientId]));
    }

    /**
     * List the recipients for the profile.
     *
     * @param  string|null  $currency  The currency filter, if any.
     * @param  bool  $active  Whether to include only active recipients.
     * @param  int|null  $seekPosition  The pagination offset, if any.
     * @param  int|null  $size  The page size, if any.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return RecipientPage The recipient page.
     *
     * @throws WiseApiError
     */
    public function list(
        ?string $currency = null,
        bool $active = true,
        ?int $seekPosition = null,
        ?int $size = null,
        ?int $profileId = null,
    ): RecipientPage {
        return RecipientPage::fromArray($this->client->requestOperation('recipients.list', query: [
            'profileId' => $this->profileId($profileId),
            'currency' => $currency === null ? null : strtoupper($currency),
            'active' => $active ? 'true' : 'false',
            'seekPosition' => $seekPosition,
            'size' => $size,
        ]));
    }

    /**
     * Delete a recipient.
     *
     * Deactivates the recipient (Wise cannot reactivate it).
     *
     * @param  int  $recipientId  The recipient ID.
     * @return Recipient The deactivated recipient.
     *
     * @throws WiseApiError
     */
    public function delete(int $recipientId): Recipient
    {
        return Recipient::fromArray($this->client->requestOperation('recipients.delete', ['recipientId' => $recipientId]));
    }

    /**
     * Build the account-requirements query parameters.
     *
     * @param  string|null  $originatorLegalEntityType  The originator legal entity type, if any.
     * @param  bool  $addressRequired  Whether the recipient address is required.
     * @return array<string, mixed> The query parameters.
     */
    private function requirementsQuery(?string $originatorLegalEntityType, bool $addressRequired): array
    {
        return [
            'originatorLegalEntityType' => $originatorLegalEntityType,
            'addressRequired' => $addressRequired ? 'true' : null,
        ];
    }

    /**
     * Add the current profile ID to a recipient payload.
     *
     * @param  array<string, mixed>  $payload  The recipient payload.
     * @return array<string, mixed> The payload with the profile ID.
     *
     * @throws MissingProfile
     */
    private function withProfile(array $payload): array
    {
        if (! isset($payload['profile'])) {
            $payload['profile'] = $this->profileId();
        }

        return $payload;
    }
}

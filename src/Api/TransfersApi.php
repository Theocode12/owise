<?php

namespace Owise\Api;

use DateTimeInterface;
use Owise\Data\FundingPayment;
use Owise\Data\FundingResult;
use Owise\Data\RequirementsForm;
use Owise\Data\Transfer;
use Owise\Exceptions\TransferCannotBeCancelled;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Idempotency;
use Owise\Support\Payload;

/**
 * Manage Wise transfers for the current profile.
 */
final class TransfersApi extends AccountApi
{
    /**
     * Get the requirements for creating a transfer.
     *
     * @param  int  $targetAccount  The recipient ID.
     * @param  string  $quoteUuid  The quote UUID.
     * @param  array<string, mixed>  $details  The transfer details.
     * @param  string|null  $customerTransactionId  The customer transaction ID, if any.
     * @param  string|null  $originatorLegalEntityType  The originator legal entity type, if any.
     * @return RequirementsForm The requirements form.
     *
     * @throws WiseApiError
     */
    public function requirements(
        int $targetAccount,
        string $quoteUuid,
        array $details = [],
        ?string $customerTransactionId = null,
        ?string $originatorLegalEntityType = null,
    ): RequirementsForm {
        $body = array_filter([
            'targetAccount' => $targetAccount,
            'quoteUuid' => $quoteUuid,
            'customerTransactionId' => $customerTransactionId,
            'originatorLegalEntityType' => $originatorLegalEntityType,
            'details' => $details === [] ? null : $details,
        ], fn (mixed $value): bool => $value !== null);

        return RequirementsForm::fromArray($this->client->requestOperation('transfers.requirements', body: $body));
    }

    /**
     * Create a transfer for the profile.
     *
     * `$customerTransactionId` is Wise's idempotency key: persist it before calling and reuse it on retry.
     *
     * @param  int  $targetAccount  The recipient ID.
     * @param  string  $quoteUuid  The quote UUID.
     * @param  string  $customerTransactionId  The customer transaction ID.
     * @param  array<string, mixed>  $details  The transfer details, e.g. `['reference' => 'Invoice 42']`.
     * @param  int|null  $sourceAccount  The source balance ID, if any.
     * @return Transfer The created transfer.
     *
     * @throws WiseApiError
     */
    public function create(
        int $targetAccount,
        string $quoteUuid,
        string $customerTransactionId,
        array $details = [],
        ?int $sourceAccount = null,
    ): Transfer {
        $body = array_filter([
            'targetAccount' => $targetAccount,
            'quoteUuid' => $quoteUuid,
            'customerTransactionId' => Idempotency::assertValid($customerTransactionId, 'customerTransactionId'),
            'sourceAccount' => $sourceAccount,
            'details' => $details === [] ? null : $details,
        ], fn (mixed $value): bool => $value !== null);

        return Transfer::fromArray($this->client->requestOperation('transfers.create', body: $body));
    }

    /**
     * Get a transfer by its ID.
     *
     * @param  int  $transferId  The transfer ID.
     * @return Transfer The transfer.
     *
     * @throws WiseApiError
     */
    public function find(int $transferId): Transfer
    {
        return Transfer::fromArray($this->client->requestOperation('transfers.find', ['transferId' => $transferId]));
    }

    /**
     * List the transfers for the profile.
     *
     * @param  string|list<string>|null  $status  The status filter, if any.
     * @param  string|null  $sourceCurrency  The source currency filter, if any.
     * @param  string|null  $targetCurrency  The target currency filter, if any.
     * @param  DateTimeInterface|null  $createdDateStart  The created-date start filter, if any.
     * @param  DateTimeInterface|null  $createdDateEnd  The created-date end filter, if any.
     * @param  int  $limit  The page limit.
     * @param  int  $offset  The page offset.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return list<Transfer> The transfers.
     *
     * @throws WiseApiError
     */
    public function list(
        string|array|null $status = null,
        ?string $sourceCurrency = null,
        ?string $targetCurrency = null,
        ?DateTimeInterface $createdDateStart = null,
        ?DateTimeInterface $createdDateEnd = null,
        int $limit = 100,
        int $offset = 0,
        ?int $profileId = null,
    ): array {
        $payload = $this->client->requestOperation('transfers.list', query: [
            'profile' => $this->profileId($profileId),
            'status' => is_array($status) ? implode(',', $status) : $status,
            'sourceCurrency' => $sourceCurrency === null ? null : strtoupper($sourceCurrency),
            'targetCurrency' => $targetCurrency === null ? null : strtoupper($targetCurrency),
            'createdDateStart' => $createdDateStart?->format('Y-m-d'),
            'createdDateEnd' => $createdDateEnd?->format('Y-m-d'),
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return array_map(Transfer::fromArray(...), Payload::arrays($payload));
    }

    /**
     * Cancel a transfer.
     *
     * Only transfers in `incoming_payment_waiting` can be cancelled; otherwise TransferCannotBeCancelled is thrown.
     *
     * @param  int  $transferId  The transfer ID.
     * @return Transfer The cancelled transfer.
     *
     * @throws TransferCannotBeCancelled
     * @throws WiseApiError
     */
    public function cancel(int $transferId): Transfer
    {
        return Transfer::fromArray($this->client->requestOperation('transfers.cancel', ['transferId' => $transferId]));
    }

    /**
     * Fund a transfer from a balance.
     *
     * Check FundingResult::isRejected(): Wise reports e.g. insufficient funds as a successful response.
     *
     * @param  int  $transferId  The transfer ID.
     * @param  string  $type  The funding type.
     * @param  int|null  $balanceId  The balance ID, if any.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return FundingResult The funding result.
     *
     * @throws WiseApiError
     */
    public function fund(int $transferId, string $type = 'BALANCE', ?int $balanceId = null, ?int $profileId = null): FundingResult
    {
        $body = array_filter(['type' => $type, 'balanceId' => $balanceId], fn (mixed $value): bool => $value !== null);

        return FundingResult::fromArray($this->client->requestOperation(
            'transfers.fund',
            [
                'profileId' => $this->profileId($profileId),
                'transferId' => $transferId,
            ],
            body: $body,
        ));
    }

    /**
     * List funding payments for a transfer. Used to reconcile an ambiguous funding outcome before
     * retrying: never blindly repeat a funding request.
     *
     * @param  int  $transferId  The transfer ID.
     * @return list<FundingPayment>
     *
     * @throws WiseApiError
     */
    public function payments(int $transferId): array
    {
        return array_map(
            FundingPayment::fromArray(...),
            Payload::arrays($this->client->requestOperation('transfers.payments', ['transferId' => $transferId])),
        );
    }
}

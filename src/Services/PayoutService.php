<?php

namespace Owise\Services;

use Brick\Money\Money as BrickMoney;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Owise\Builders\PayoutBuilder;
use Owise\Data\FundingPayment;
use Owise\Data\Quote;
use Owise\Data\QuoteOptions;
use Owise\Data\RequirementsForm;
use Owise\Data\Transfer;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Events\TransferAwaitingFunding;
use Owise\Events\TransferCreated;
use Owise\Events\TransferFunded;
use Owise\Exceptions\IdempotencyConflict;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\MissingProfile;
use Owise\Exceptions\PermissionDenied;
use Owise\Exceptions\RecipientOwnershipMismatch;
use Owise\Exceptions\TransferFundingOutcomeUnknown;
use Owise\Exceptions\TransferFundingRejected;
use Owise\Exceptions\TransferRequirementsMissing;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;
use Owise\Support\Idempotency;
use Owise\Support\Money;
use Owise\Support\WiseEventDispatcher;

/**
 * Executes the payout workflow (quote → requirements → transfer → fund) against a persisted
 * WiseTransfer mirror. Remote calls are intentionally not wrapped in a single local transaction:
 * recovery relies on Wise's `customerTransactionId` idempotency, funding reconciliation and
 * resumable status transitions.
 */
final class PayoutService
{
    /**
     * Preview a quote for the payout without persisting anything.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfiguration
     * @throws MissingProfile
     * @throws RecipientOwnershipMismatch
     * @throws WiseApiError
     */
    public function preview(PayoutBuilder $builder): Quote
    {
        $intent = $this->resolveIntent($builder);
        $quoteOptions = $intent['quote_options'];
        $preferredPayIn = $quoteOptions['preferredPayIn'] ?? null;
        unset($quoteOptions['preferredPayIn']);

        return $intent['profile']->quotes()->create(
            sourceCurrency: $intent['source_currency'],
            targetCurrency: $intent['target_currency'],
            sourceAmount: $intent['amount_is_source'] ? $intent['amount'] : null,
            targetAmount: $intent['amount_is_source'] ? null : $intent['amount'],
            targetAccount: $intent['recipient_id'],
            payOut: 'BANK_TRANSFER',
            preferredPayIn: is_string($preferredPayIn) ? $preferredPayIn : null,
            options: $quoteOptions,
        );
    }

    /**
     * Send the payout through the quote, transfer, and funding steps.
     *
     * The draft is created first so retries reuse the same idempotency key.
     *
     * @throws IdempotencyConflict
     * @throws InvalidArgumentException
     * @throws InvalidConfiguration
     * @throws MissingProfile
     * @throws RecipientOwnershipMismatch
     * @throws TransferFundingOutcomeUnknown
     * @throws TransferFundingRejected
     * @throws TransferRequirementsMissing
     * @throws WiseApiError
     */
    public function send(PayoutBuilder $builder): WiseTransfer
    {
        $transfer = $this->createDraft($builder);

        return $this->resume($transfer, $builder);
    }

    /**
     * Persist (or load) the payable's payout under the composite idempotency key, in `draft` state,
     * before any Wise call. A concurrent insert collision loads and validates the winning row.
     */
    public function createDraft(PayoutBuilder $builder): WiseTransfer
    {
        $intent = $this->resolveIntent($builder);

        $idempotencyKey = is_string($intent['idempotency_key']) ? trim($intent['idempotency_key']) : null;
        if ($idempotencyKey === null || $idempotencyKey === '') {
            throw new InvalidArgumentException('A payout idempotency key is required. Call ->idempotencyKey() with a value that is stable for this payout (for example "invoice-42").');
        }
        if (strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('A payout idempotency key cannot be longer than 255 characters.');
        }
        $intent['idempotency_key'] = $idempotencyKey;

        $payable = $builder->payable;
        $modelClass = $this->transferModelClass();

        $existing = $this->findByIdempotencyKey($payable, $modelClass, $intent['idempotency_key']);
        if ($existing !== null) {
            $this->assertIntentMatches($existing, $intent);

            return $existing;
        }

        $transfer = new $modelClass;
        $transfer->forceFill(array_merge($this->draftAttributes($intent), [
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
        ]));

        try {
            DB::transaction(fn () => $transfer->save()); // savepoint when nested; own transaction otherwise
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotencyKey($payable, $modelClass, $intent['idempotency_key'])
                ?? throw $exception;
            $this->assertIntentMatches($existing, $intent);

            return $existing;
        }

        return $transfer;
    }

    /**
     * Continue the workflow from the last persisted step under a row lock.
     */
    public function resume(WiseTransfer $transfer, ?PayoutBuilder $builder = null): WiseTransfer
    {
        // A distributed cache lock serializes all entry points without holding a database
        // transaction open across Wise HTTP calls. Each successful step is therefore durable
        // even if a later request fails.
        return Cache::lock('wise:payout:'.$transfer->getKey(), 600)->block(60, function () use ($transfer, $builder): WiseTransfer {
            $locked = $transfer->newQuery()->whereKey($transfer->getKey())->firstOrFail();
            $this->applyRetryInputs($locked, $builder);

            return $this->advance($locked);
        });
    }

    /**
     * Retry funding a created API-funded transfer whose earlier attempt is pending or unknown.
     *
     * Operator escape hatch: confirm in Wise that the earlier attempt did not fund before calling.
     *
     * @throws WiseException
     * @throws WiseApiError
     */
    public function retryFunding(WiseTransfer $transfer): WiseTransfer
    {
        return Cache::lock('wise:payout:'.$transfer->getKey(), 600)->block(60, function () use ($transfer): WiseTransfer {
            $locked = $transfer->newQuery()->whereKey($transfer->getKey())->firstOrFail();

            if ($locked->status !== TransferStatus::Created
                || $locked->funding_mode !== FundingMode::Api
                || ! in_array($locked->funding_status, [FundingStatus::Pending, FundingStatus::Unknown], true)) {
                throw new WiseException("Transfer {$locked->getKey()} cannot have funding retried; it must be created, API-funded and pending or unknown.");
            }

            $locked->refreshFromWise();
            $this->reconcileFunding($locked);

            if ($locked->hasBeenFunded() || $locked->funding_status === FundingStatus::Completed) {
                return $locked;
            }

            if ($locked->wise_status !== WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING) {
                throw new WiseException("Transfer {$locked->getKey()} is in Wise status '{$locked->wise_status}' and cannot be funded.");
            }

            $locked->forceFill(['funding_status' => null])->save();

            return $this->advance($locked);
        });
    }

    /**
     * Advance the transfer to the next persisted workflow step.
     *
     * Each completed step is durable so resume can continue after a failure.
     *
     * @throws TransferFundingOutcomeUnknown
     * @throws TransferFundingRejected
     * @throws TransferRequirementsMissing
     * @throws WiseApiError
     */
    private function advance(WiseTransfer $transfer): WiseTransfer
    {
        if ($transfer->status === TransferStatus::Draft) {
            $this->createQuote($transfer);
        }

        if ($transfer->status === TransferStatus::Quoted) {
            $this->createTransfer($transfer);
        }

        if ($transfer->status === TransferStatus::Created) {
            $this->fundTransfer($transfer);
        }

        return $transfer;
    }

    /**
     * Create the Wise quote for a drafted transfer and mark it quoted.
     *
     * @throws InvalidArgumentException
     * @throws MissingProfile
     * @throws WiseApiError
     */
    private function createQuote(WiseTransfer $transfer): void
    {
        $quoteOptions = is_array($transfer->meta['quote_options'] ?? null) ? $transfer->meta['quote_options'] : [];
        $preferredPayIn = $quoteOptions['preferredPayIn'] ?? null;
        unset($quoteOptions['preferredPayIn']);

        $quote = $transfer->profile()->quotes()->create(
            sourceCurrency: $transfer->source_currency,
            targetCurrency: $transfer->target_currency,
            sourceAmount: $transfer->source_amount !== null
                ? Money::fromMinor($transfer->source_amount, $transfer->source_currency)
                : null,
            targetAmount: $transfer->target_amount !== null
                ? Money::fromMinor($transfer->target_amount, $transfer->target_currency)
                : null,
            targetAccount: (int) $transfer->wise_recipient_id,
            payOut: 'BANK_TRANSFER',
            preferredPayIn: is_string($preferredPayIn) ? $preferredPayIn : null,
            transferNature: is_string($transfer->meta['transfer_nature'] ?? null)
                ? $transfer->meta['transfer_nature']
                : null,
            options: $quoteOptions,
        );

        $payIn = $quote->preferredPayIn ?? (is_string($preferredPayIn) ? $preferredPayIn : 'BANK_TRANSFER');
        $paymentOption = $quote->paymentOption($payIn, 'BANK_TRANSFER');
        $sourceAmount = $quote->sourceAmount ?? $paymentOption?->sourceAmount;
        $targetAmount = $quote->targetAmount ?? $paymentOption?->targetAmount;

        $meta = $transfer->meta ?? [];
        $meta['quote_expires_at'] = $quote->expirationTime?->format(DATE_ATOM);

        $transfer->forceFill([
            'wise_quote_id' => $quote->id,
            'source_currency' => $quote->sourceCurrency,
            'target_currency' => $quote->targetCurrency,
            'source_amount' => $sourceAmount !== null ? Money::toMinor($sourceAmount) : null,
            'target_amount' => $targetAmount !== null ? Money::toMinor($targetAmount) : null,
            'fee_amount' => $this->quoteFee($quote, $payIn),
            'rate' => $quote->rate !== null ? (string) $quote->rate : null,
            'meta' => $meta,
            'status' => TransferStatus::Quoted,
        ])->save();
    }

    /**
     * Create the Wise transfer for a quoted transfer and mark it created.
     *
     * @throws TransferRequirementsMissing
     * @throws WiseApiError
     */
    private function createTransfer(WiseTransfer $transfer): void
    {
        $details = $this->transferDetailsFor($transfer);

        $meta = $transfer->meta ?? [];
        $quoteExpired = is_string($meta['quote_expires_at'] ?? null)
            && $meta['quote_expires_at'] !== ''
            && now()->greaterThanOrEqualTo(CarbonImmutable::parse($meta['quote_expires_at']));
        $createAttempted = array_key_exists('transfer_create_attempted_at', $meta);

        if ($quoteExpired && ! $createAttempted) {
            $this->requote($transfer);
        }

        $this->resolveRequirements($transfer, $details);

        // Record the create attempt before the remote call so a lost response is recoverable.
        $meta = $transfer->meta ?? [];
        $meta['transfer_create_attempted_at'] = now()->toIso8601String();
        $transfer->forceFill(['meta' => $meta])->save();

        try {
            $remote = $this->createRemoteTransfer($transfer, $details);
        } catch (WiseApiError $e) {
            if ($quoteExpired && $createAttempted && in_array($e->status, [400, 404, 409, 410, 422], true)) {
                // The expired quote could not create a transfer; re-quote and retry once.
                $this->requote($transfer);
                $this->resolveRequirements($transfer, $details);

                $remote = $this->createRemoteTransfer($transfer, $details);
            } else {
                throw $e;
            }
        }

        $transfer->forceFill([
            'wise_transfer_id' => $remote->id,
            'wise_status' => $remote->status,
            'status' => TransferStatus::Created,
        ])->save();

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferCreated($transfer));
    }

    /**
     * Issue the Wise transfer create request for a quoted transfer.
     *
     * @param  array<string, mixed>  $details
     */
    private function createRemoteTransfer(WiseTransfer $transfer, array $details): Transfer
    {
        return $transfer->profile()->transfers()->create(
            targetAccount: (int) $transfer->wise_recipient_id,
            quoteUuid: (string) $transfer->wise_quote_id,
            customerTransactionId: $transfer->customer_transaction_id,
            details: $details,
        );
    }

    /**
     * Clear the expired quote and create a fresh one, preserving the fixed side of the intent.
     */
    private function requote(WiseTransfer $transfer): void
    {
        $amountIsSource = (bool) ($transfer->meta['amount_is_source'] ?? false);

        $transfer->forceFill([
            'wise_quote_id' => null,
            'fee_amount' => null,
            'rate' => null,
            'source_amount' => $amountIsSource ? $transfer->source_amount : null,
            'target_amount' => $amountIsSource ? null : $transfer->target_amount,
            'status' => TransferStatus::Draft,
        ])->save();

        $this->createQuote($transfer);
    }

    /**
     * Fund a created transfer from the profile balance.
     *
     * Manual funding parks the transfer in awaiting-funding state instead of calling Wise.
     *
     * @throws PermissionDenied
     * @throws TransferFundingOutcomeUnknown
     * @throws TransferFundingRejected
     * @throws WiseApiError
     */
    private function fundTransfer(WiseTransfer $transfer): void
    {
        if ($transfer->funding_mode === FundingMode::Manual) {
            $transfer->forceFill([
                'status' => TransferStatus::AwaitingFunding,
                'wise_status' => $transfer->wise_status ?? WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING,
            ])->save();

            app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferAwaitingFunding($transfer));

            return;
        }

        $this->reconcileFunding($transfer);

        if ($transfer->hasBeenFunded() || $transfer->funding_status === FundingStatus::Created) {
            return;
        }

        if (in_array($transfer->funding_status, [FundingStatus::Pending, FundingStatus::Unknown], true)) {
            $transfer->forceFill(['funding_status' => FundingStatus::Unknown])->save();

            throw new TransferFundingOutcomeUnknown($transfer);
        }

        // Persist before the remote call. If it times out or the connection drops, a later
        // resume can reconcile the completed-payments list but will not blindly fund again.
        $transfer->forceFill([
            'funding_status' => FundingStatus::Pending,
            'funding_attempted_at' => now(),
        ])->save();

        try {
            $result = $transfer->profile()->transfers()->fund((int) $transfer->wise_transfer_id);
        } catch (WiseApiError $e) {
            // Definite client rejections (SCA 403, 401, 422, PermissionDenied, …) are terminal
            // and should not be retried as if the outcome were unknown. Connection failures,
            // 5xx, 408 and 429 leave `Pending` so the next resume reconciles.
            if ($e->status >= 400 && $e->status < 500 && ! in_array($e->status, [408, 429], true)) {
                $transfer->forceFill([
                    'funding_status' => FundingStatus::Rejected,
                    'failure_reason' => mb_substr($e->getMessage(), 0, 255),
                ])->save();
            }

            throw $e;
        }

        if ($result->isRejected()) {
            $transfer->forceFill([
                'funding_status' => FundingStatus::Rejected,
                'funding_attempted_at' => $transfer->funding_attempted_at ?? now(),
                'failure_reason' => $result->errorMessage ?? $result->errorCode,
            ])->save();

            throw new TransferFundingRejected(
                $result->errorMessage ?? 'Wise rejected the transfer funding.',
                $result->errorCode,
                $result->errorMessage,
            );
        }

        if ($result->isCompleted()) {
            $transfer->forceFill([
                'funding_status' => FundingStatus::Completed,
                'funding_attempted_at' => $transfer->funding_attempted_at ?? now(),
                'funded_at' => $this->confirmedFundingAt($transfer),
                'balance_transaction_id' => $result->balanceTransactionId,
            ])->save();

            app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferFunded($transfer));

            return;
        }

        // CREATED: an accepted, in-progress attempt. `funded_at` is set only when funding is
        // confirmed by Wise (a later state or a COMPLETED reconciliation).
        $transfer->forceFill([
            'funding_status' => FundingStatus::Created,
            'funding_attempted_at' => $transfer->funding_attempted_at ?? now(),
            'balance_transaction_id' => $result->balanceTransactionId,
        ])->save();
    }

    /**
     * Reconcile an ambiguous funding attempt against Wise's payment list.
     *
     * Marks the transfer completed when Wise already lists a funding payment.
     *
     * @throws WiseApiError
     */
    private function reconcileFunding(WiseTransfer $transfer): void
    {
        $transferId = $transfer->wise_transfer_id;
        if ($transferId === null || $transfer->hasBeenFunded()) {
            return;
        }

        $payments = $transfer->profile()->transfers()->payments((int) $transferId);
        if ($payments !== []) {
            $transfer->forceFill([
                'funding_status' => FundingStatus::Completed,
                'funded_at' => $transfer->funded_at ?? $this->paymentTimestamp($payments) ?? now(),
            ])->save();

            app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferFunded($transfer));
        }
    }

    /**
     * Prefer Wise's funding-payment timestamp; fall back to when the successful response was
     * observed if the payment list is unavailable or does not include a timestamp.
     */
    private function confirmedFundingAt(WiseTransfer $transfer): DateTimeInterface
    {
        try {
            $payments = $transfer->profile()->transfers()->payments((int) $transfer->wise_transfer_id);
        } catch (WiseException) {
            return now();
        }

        return $this->paymentTimestamp($payments) ?? now();
    }

    /**
     * @param  list<FundingPayment>  $payments
     */
    private function paymentTimestamp(array $payments): ?DateTimeInterface
    {
        $timestamps = [];
        foreach ($payments as $payment) {
            $timestamp = $payment->timeCreated ?? $payment->timeUpdated;
            if ($timestamp !== null) {
                $timestamps[] = $timestamp;
            }
        }

        usort($timestamps, static fn (DateTimeInterface $left, DateTimeInterface $right): int => $left <=> $right);

        return $timestamps[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function resolveRequirements(WiseTransfer $transfer, array $details): RequirementsForm
    {
        $originatorLegalEntityType = $transfer->meta['originator_legal_entity_type'] ?? null;

        $form = $transfer->profile()->transfers()->requirements(
            targetAccount: (int) $transfer->wise_recipient_id,
            quoteUuid: (string) $transfer->wise_quote_id,
            details: $details,
            customerTransactionId: $transfer->customer_transaction_id,
            originatorLegalEntityType: is_string($originatorLegalEntityType) ? $originatorLegalEntityType : null,
        );

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $missing = $this->missingRequiredFields($form, $details);
            if ($missing !== []) {
                throw new TransferRequirementsMissing(
                    'Wise transfer requirements still need: '.implode(', ', $missing).'. Retry with ->transferDetails() (and quote options if needed).',
                    $form,
                    $transfer,
                );
            }

            if (! $this->hasSuppliedRefreshableField($form, $details)) {
                break;
            }

            $form = $transfer->profile()->transfers()->requirements(
                targetAccount: (int) $transfer->wise_recipient_id,
                quoteUuid: (string) $transfer->wise_quote_id,
                details: $details,
                customerTransactionId: $transfer->customer_transaction_id,
                originatorLegalEntityType: is_string($originatorLegalEntityType) ? $originatorLegalEntityType : null,
            );
        }

        return $form;
    }

    /** @return array<string, mixed> */
    private function transferDetailsFor(WiseTransfer $transfer): array
    {
        $meta = $transfer->meta ?? [];
        $details = is_array($meta['transfer_details'] ?? null) ? $meta['transfer_details'] : [];

        $reference = $transfer->reference;
        if ($reference !== null && $reference !== '' && ! array_key_exists('reference', $details)) {
            $details['reference'] = $reference;
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function missingRequiredFields(RequirementsForm $form, array $details): array
    {
        $missing = [];
        foreach ($form->types as $type) {
            foreach ($type->fields as $field) {
                if (! $field->required) {
                    continue;
                }
                $key = str_replace('/', '.', $field->key);
                if (! array_key_exists($key, $details)) {
                    $missing[] = $field->key;
                }
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function hasSuppliedRefreshableField(RequirementsForm $form, array $details): bool
    {
        foreach ($form->types as $type) {
            foreach ($type->fields as $field) {
                $key = str_replace('/', '.', $field->key);
                if ($field->refreshRequirementsOnChange && array_key_exists($key, $details)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the pay-in fee for the quote in minor units.
     */
    private function quoteFee(Quote $quote, string $payIn): ?int
    {
        $fee = $quote->paymentOption($payIn, 'BANK_TRANSFER')?->fee;

        return $fee === null ? null : Money::toMinor($fee);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIntent(PayoutBuilder $builder): array
    {
        $this->assertPayablePersisted($builder->payable);

        $amountMoney = $builder->amount instanceof BrickMoney ? $builder->amount : null;

        $targetCurrencyHint = null;
        if (! $builder->amountIsSource) {
            $targetCurrencyHint = $amountMoney?->getCurrency()->getCurrencyCode() ?? $builder->currency;
        }

        $recipient = $this->resolveRecipient($builder, $targetCurrencyHint);
        $targetCurrency = strtoupper($recipient->currency);

        $sourceCurrency = $builder->sourceCurrency ?? config('wise.payouts.source_currency');
        if ($sourceCurrency === null && $builder->amountIsSource) {
            $sourceCurrency = $amountMoney?->getCurrency()->getCurrencyCode() ?? $builder->currency;
        }
        if (! is_string($sourceCurrency) || trim($sourceCurrency) === '') {
            throw new InvalidConfiguration('A source currency is required: pass ->from() or set wise.payouts.source_currency.');
        }
        $sourceCurrency = strtoupper($sourceCurrency);

        $amount = $this->resolveAmount($builder, $sourceCurrency, $targetCurrency);
        $profile = $recipient->profile();
        $fundingMode = $builder->fundingMode ?? $profile->context()->funding;
        $quoteOptions = $this->payoutQuoteOptions($builder, $fundingMode);

        $intent = [
            'profile' => $profile,
            'account' => $profile->context()->name,
            'profile_id' => $profile->profileId(),
            'recipient_id' => (int) $recipient->wise_recipient_id,
            'recipient_key' => $recipient->getKey(),
            'target_currency' => $targetCurrency,
            'source_currency' => $sourceCurrency,
            'amount_is_source' => $builder->amountIsSource,
            'amount' => $amount,
            'amount_minor' => Money::toMinor($amount),
            'amount_currency' => $amount->getCurrency()->getCurrencyCode(),
            'funding_mode' => $fundingMode,
            'reference' => $builder->reference,
            'transfer_details' => $builder->transferDetails,
            'originator_legal_entity_type' => $builder->originatorLegalEntityType,
            'transfer_nature' => $builder->transferNature,
            'quote_options' => $quoteOptions,
            'meta' => $builder->meta,
            'idempotency_key' => $builder->idempotencyKey,
        ];
        $intent['intent_hash'] = $this->intentHash($intent);

        return $intent;
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutQuoteOptions(PayoutBuilder $builder, FundingMode $fundingMode): array
    {
        return QuoteOptions::fromArray($builder->quoteOptions)
            ->withTransferNature($builder->transferNature)
            ->withDefaultPreferredPayIn($fundingMode === FundingMode::Api ? 'BALANCE' : 'BANK_TRANSFER')
            ->toArray();
    }

    /**
     * Resolve the payout amount and validate its currency.
     *
     * @throws InvalidArgumentException
     */
    private function resolveAmount(PayoutBuilder $builder, string $sourceCurrency, string $targetCurrency): BrickMoney
    {
        if ($builder->amount instanceof BrickMoney) {
            $amount = $builder->amount;
        } else {
            $currency = $builder->currency ?? ($builder->amountIsSource ? $sourceCurrency : $targetCurrency);
            $amount = Money::fromDecimal($builder->amount, $currency);
        }

        $expected = $builder->amountIsSource ? $sourceCurrency : $targetCurrency;
        if ($amount->getCurrency()->getCurrencyCode() !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'The payout amount currency (%s) must match the %s currency (%s).',
                $amount->getCurrency()->getCurrencyCode(),
                $builder->amountIsSource ? 'source' : 'target',
                $expected,
            ));
        }

        return $amount;
    }

    /**
     * Resolve the payout recipient owned by the payable.
     *
     * Falls back to the payable's default recipient for the target currency.
     *
     * @param  string|null  $currencyHint  The target currency used to select the default recipient.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfiguration
     * @throws RecipientOwnershipMismatch
     */
    private function resolveRecipient(PayoutBuilder $builder, ?string $currencyHint): WiseRecipient
    {
        if ($builder->recipient instanceof WiseRecipient) {
            $recipient = $builder->recipient;
            $payable = $builder->payable;

            if (! $recipient->exists
                || $recipient->payable_type !== $payable->getMorphClass()
                || (string) $recipient->payable_id !== (string) $payable->getKey()) {
                throw new RecipientOwnershipMismatch;
            }

            return $recipient;
        }

        $payable = $builder->payable;
        $modelClass = $this->recipientModelClass();

        if (is_int($builder->recipient)) {
            $recipient = $modelClass::query()
                ->where('payable_type', $payable->getMorphClass())
                ->where('payable_id', $payable->getKey())
                ->find($builder->recipient);
            if ($recipient === null) {
                throw new InvalidArgumentException("No Wise recipient with id {$builder->recipient} belongs to this payable.");
            }

            return $recipient;
        }

        $query = $modelClass::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->active()
            ->where('is_default', true);
        if ($currencyHint !== null) {
            $query->forCurrency($currencyHint);
        }

        $recipient = $query->first();
        if ($recipient === null) {
            throw new InvalidArgumentException('Pass ->to() or configure a default Wise recipient for the target currency.');
        }

        return $recipient;
    }

    /**
     * Apply new requirement details supplied when retrying an incomplete payout. Core intent
     * fields are checked separately by the idempotency hash and cannot be changed here.
     */
    private function applyRetryInputs(WiseTransfer $transfer, ?PayoutBuilder $builder): void
    {
        if ($builder === null || ($builder->transferDetails === [] && $builder->originatorLegalEntityType === null)) {
            return;
        }

        if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Quoted], true)
            || $transfer->wise_transfer_id !== null) {
            return;
        }

        $meta = $transfer->meta ?? [];
        $existingDetails = is_array($meta['transfer_details'] ?? null) ? $meta['transfer_details'] : [];
        if ($builder->transferDetails !== []) {
            $meta['transfer_details'] = array_replace($existingDetails, $builder->transferDetails);
        }
        if ($builder->originatorLegalEntityType !== null) {
            $meta['originator_legal_entity_type'] = $builder->originatorLegalEntityType;
        }

        $transfer->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function draftAttributes(array $intent): array
    {
        return [
            'wise_recipient_id' => $intent['recipient_id'],
            'recipient_id' => $intent['recipient_key'],
            'account' => $intent['account'],
            'wise_connection_id' => null,
            'profile_id' => $intent['profile_id'],
            'customer_transaction_id' => Idempotency::uuid(),
            'idempotency_key' => $intent['idempotency_key'],
            'status' => TransferStatus::Draft,
            'funding_mode' => $intent['funding_mode'],
            'source_currency' => $intent['source_currency'],
            'target_currency' => $intent['target_currency'],
            'source_amount' => $intent['amount_is_source'] ? $intent['amount_minor'] : null,
            'target_amount' => $intent['amount_is_source'] ? null : $intent['amount_minor'],
            'reference' => $intent['reference'],
            'meta' => $this->intentMeta($intent),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function intentMeta(array $intent): array
    {
        $meta = $intent['meta'];
        $meta['intent_hash'] = $intent['intent_hash'];
        $meta['amount_is_source'] = $intent['amount_is_source'];
        if ($intent['transfer_details'] !== []) {
            $meta['transfer_details'] = $intent['transfer_details'];
        }
        if ($intent['originator_legal_entity_type'] !== null) {
            $meta['originator_legal_entity_type'] = $intent['originator_legal_entity_type'];
        }
        if ($intent['transfer_nature'] !== null) {
            $meta['transfer_nature'] = $intent['transfer_nature'];
        }
        if ($intent['quote_options'] !== []) {
            $meta['quote_options'] = $intent['quote_options'];
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function intentHash(array $intent): string
    {
        $canonical = [
            'account' => $intent['account'],
            'profile_id' => $intent['profile_id'],
            'target_currency' => $intent['target_currency'],
            'source_currency' => $intent['source_currency'],
            'amount_is_source' => $intent['amount_is_source'],
            'amount_minor' => $intent['amount_minor'],
            'amount_currency' => $intent['amount_currency'],
            'recipient_id' => $intent['recipient_id'],
            'funding_mode' => $intent['funding_mode']->value,
            'reference' => $intent['reference'],
            'quote_options' => $intent['quote_options'],
        ];

        return hash('sha256', json_encode($this->sortAssociativeKeys($canonical), JSON_THROW_ON_ERROR));
    }

    /**
     * Sort associative arrays recursively so equivalent options hash identically regardless of
     * the order in which callers supplied their keys. Lists retain their order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortAssociativeKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociativeKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function assertIntentMatches(WiseTransfer $existing, array $intent): void
    {
        $meta = $existing->meta ?? [];
        if (($meta['intent_hash'] ?? null) !== $intent['intent_hash']) {
            throw new IdempotencyConflict(
                'The idempotency key was already used for a payout with a different profile, recipient, amount, currency, funding mode, reference, or quote options.',
                $existing,
            );
        }

        if ($existing->wise_transfer_id !== null) {
            $existingDetails = is_array($meta['transfer_details'] ?? null) ? $meta['transfer_details'] : [];
            $submittedDetails = $intent['transfer_details'];
            if ($submittedDetails !== []
                && $this->sortAssociativeKeys(array_replace($existingDetails, $submittedDetails)) !== $this->sortAssociativeKeys($existingDetails)) {
                throw new IdempotencyConflict(
                    'Transfer requirement details cannot be changed after the Wise transfer has been created.',
                    $existing,
                );
            }

            $existingOriginatorType = $meta['originator_legal_entity_type'] ?? null;
            if ($intent['originator_legal_entity_type'] !== null
                && $existingOriginatorType !== $intent['originator_legal_entity_type']) {
                throw new IdempotencyConflict(
                    'The originator legal entity type cannot be changed after the Wise transfer has been created.',
                    $existing,
                );
            }
        }
    }

    /**
     * Find a payable payout by its idempotency key.
     *
     * @param  class-string<WiseTransfer>  $modelClass  The transfer model class to query.
     */
    private function findByIdempotencyKey(Model $payable, string $modelClass, string $idempotencyKey): ?WiseTransfer
    {
        return $modelClass::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Assert the payable exists before building a payout.
     *
     * @throws InvalidArgumentException
     */
    private function assertPayablePersisted(Model $payable): void
    {
        if (! $payable->exists || $payable->getKey() === null) {
            throw new InvalidArgumentException('Persist the payable model before creating a payout.');
        }
    }

    /** @return class-string<WiseTransfer> */
    private function transferModelClass(): string
    {
        $class = config('wise.models.transfer', WiseTransfer::class);
        if (! is_string($class) || ! is_a($class, WiseTransfer::class, true)) {
            throw new InvalidConfiguration('wise.models.transfer must be a WiseTransfer model class.');
        }

        return $class;
    }

    /** @return class-string<WiseRecipient> */
    private function recipientModelClass(): string
    {
        $class = config('wise.models.recipient', WiseRecipient::class);
        if (! is_string($class) || ! is_a($class, WiseRecipient::class, true)) {
            throw new InvalidConfiguration('wise.models.recipient must be a WiseRecipient model class.');
        }

        return $class;
    }
}

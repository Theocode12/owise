<?php

namespace Owise\Models;

use Brick\Money\Money as BrickMoney;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Owise\Data\Transfer as WiseTransferData;
use Owise\Database\Factories\WiseTransferFactory;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Enums\WiseTransferStatus;
use Owise\Events\TransferCompleted;
use Owise\Events\TransferFailed;
use Owise\Events\TransferReturned;
use Owise\Events\TransferStatusUpdated;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\WiseApiError;
use Owise\Exceptions\WiseException;
use Owise\Services\PayoutService;
use Owise\Support\Money;
use Owise\Support\WiseEventDispatcher;
use Owise\WiseManager;
use Owise\WiseProfile;

/**
 * Local mirror of a Wise transfer created through the payout workflow, kept in sync by webhooks
 * and reconciliation. The local `status` tracks the workflow step; `wise_status` tracks the last
 * Wise status.
 *
 * @property int $id
 * @property string|null $payable_type
 * @property int|string|null $payable_id
 * @property int|null $recipient_id
 * @property int|null $wise_recipient_id
 * @property string|null $account
 * @property int|null $wise_connection_id
 * @property int $profile_id
 * @property string $customer_transaction_id
 * @property string|null $idempotency_key
 * @property string|null $wise_quote_id
 * @property int|null $wise_transfer_id
 * @property TransferStatus $status
 * @property string|null $wise_status
 * @property FundingMode $funding_mode
 * @property FundingStatus|null $funding_status
 * @property DateTimeInterface|null $funding_attempted_at
 * @property DateTimeInterface|null $funded_at
 * @property int|null $balance_transaction_id
 * @property string $source_currency
 * @property int|null $source_amount
 * @property string $target_currency
 * @property int|null $target_amount
 * @property int|null $fee_amount
 * @property string|null $rate
 * @property string|null $reference
 * @property DateTimeInterface|null $wise_status_occurred_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 * @property-read Model|null $payable
 * @property-read WiseRecipient|null $recipient
 */
class WiseTransfer extends Model
{
    /** @use HasFactory<WiseTransferFactory> */
    use HasFactory;

    public const WISE_STATUS_INCOMING_PAYMENT_WAITING = WiseTransferStatus::IncomingPaymentWaiting->value;

    public const WISE_STATUS_INCOMING_PAYMENT_INITIATED = WiseTransferStatus::IncomingPaymentInitiated->value;

    public const WISE_STATUS_PROCESSING = WiseTransferStatus::Processing->value;

    public const WISE_STATUS_FUNDS_CONVERTED = WiseTransferStatus::FundsConverted->value;

    public const WISE_STATUS_OUTGOING_PAYMENT_SENT = WiseTransferStatus::OutgoingPaymentSent->value;

    public const WISE_STATUS_CANCELLED = WiseTransferStatus::Cancelled->value;

    public const WISE_STATUS_FUNDS_REFUNDED = WiseTransferStatus::FundsRefunded->value;

    public const WISE_STATUS_BOUNCED_BACK = WiseTransferStatus::BouncedBack->value;

    public const WISE_STATUS_CHARGED_BACK = WiseTransferStatus::ChargedBack->value;

    protected $table = 'wise_transfers';

    protected $guarded = [];

    /**
     * Get the date format used for date columns.
     */
    public function getDateFormat(): string
    {
        // Wise webhook ordering uses millisecond-precision timestamps; preserve them so
        // `wise_status_occurred_at` comparisons are exact.
        return 'Y-m-d H:i:s.v';
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WiseTransferFactory
    {
        return WiseTransferFactory::new();
    }

    /**
     * Get the parent payable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the Wise recipient for the transfer.
     *
     * @return BelongsTo<WiseRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo($this->recipientModelClass(), 'recipient_id')->withTrashed();
    }

    /**
     * Get the Wise profile for the transfer. Falls back to the default
     * profile when the transfer has no account set.
     */
    public function profile(): WiseProfile
    {
        $account = $this->getAttribute('account');
        $manager = app(WiseManager::class);

        return is_string($account) && $account !== '' ? $manager->profile($account) : $manager->profile();
    }

    /**
     * Determine if the transfer is still pending.
     */
    public function isPending(): bool
    {
        return ! $this->isCompleted() && ! $this->isFailed();
    }

    /**
     * Determine if the transfer is completed.
     */
    public function isCompleted(): bool
    {
        return $this->knownWiseStatus() === WiseTransferStatus::OutgoingPaymentSent;
    }

    /**
     * Determine if the transfer has failed.
     */
    public function isFailed(): bool
    {
        return $this->getAttribute('status') === TransferStatus::FailedToCreate
            || $this->knownWiseStatus()?->isFailed() === true;
    }

    /**
     * Determine if the transfer can be cancelled.
     */
    public function isCancellable(): bool
    {
        return $this->knownWiseStatus() === WiseTransferStatus::IncomingPaymentWaiting
            || $this->getAttribute('status') === TransferStatus::AwaitingFunding;
    }

    /**
     * Determine if the transfer is awaiting funding.
     */
    public function awaitingFunding(): bool
    {
        return $this->getAttribute('status') === TransferStatus::AwaitingFunding
            || $this->knownWiseStatus() === WiseTransferStatus::IncomingPaymentWaiting;
    }

    /**
     * Determine if the transfer was returned.
     */
    public function isReturned(): bool
    {
        return $this->knownWiseStatus() === WiseTransferStatus::BouncedBack;
    }

    /**
     * Determine if the transfer has been funded.
     */
    public function hasBeenFunded(): bool
    {
        $status = WiseTransferStatus::tryFrom((string) $this->getAttribute('wise_status'));

        return $this->getAttribute('funded_at') !== null || $status?->indicatesPreviouslyFunded() === true;
    }

    /**
     * Get the source amount as a money object.
     */
    public function sourceAmount(): BrickMoney
    {
        return Money::fromMinor((int) $this->getAttribute('source_amount'), (string) $this->getAttribute('source_currency'));
    }

    /**
     * Get the target amount as a money object.
     */
    public function targetAmount(): BrickMoney
    {
        return Money::fromMinor((int) $this->getAttribute('target_amount'), (string) $this->getAttribute('target_currency'));
    }

    /**
     * Get the fee as a money object.
     */
    public function fee(): ?BrickMoney
    {
        $fee = $this->getAttribute('fee_amount');

        return $fee === null ? null : Money::fromMinor((int) $fee, (string) $this->getAttribute('source_currency'));
    }

    /**
     * Get the formatted source amount.
     */
    public function formattedSourceAmount(?string $locale = null): string
    {
        return Money::format($this->sourceAmount(), $locale);
    }

    /**
     * Get the formatted target amount.
     */
    public function formattedTargetAmount(?string $locale = null): string
    {
        return Money::format($this->targetAmount(), $locale);
    }

    /**
     * Get the formatted fee.
     */
    public function formattedFee(?string $locale = null): string
    {
        return $this->fee() === null ? '' : Money::format($this->fee(), $locale);
    }

    /**
     * Continue the workflow from the last persisted step under a row lock, reconciling uncertain
     * remote outcomes before retrying calls that Wise does not protect with idempotency.
     */
    public function resume(): self
    {
        return app(PayoutService::class)->resume($this);
    }

    /**
     * Retry funding a created API-funded transfer whose earlier attempt outcome is unknown or
     * rejected. Operator-only: confirm in Wise that the earlier attempt did not fund before calling.
     */
    public function retryFunding(): self
    {
        return app(PayoutService::class)->retryFunding($this);
    }

    /**
     * Fetch the authoritative transfer from Wise and apply its status.
     */
    public function refreshFromWise(bool $dispatchEvents = true): self
    {
        $transferId = $this->getAttribute('wise_transfer_id');
        if ($transferId === null) {
            return $this;
        }

        $remote = $this->profile()->transfers()->find((int) $transferId);

        return $this->applyRemoteStatus($remote->status, null, $remote, $dispatchEvents);
    }

    /**
     * Cancel the transfer in Wise. Only transfers still in `incoming_payment_waiting` can be
     * cancelled; otherwise WiseApiError/TransferCannotBeCancelled is thrown.
     */
    public function cancel(bool $dispatchEvents = true): self
    {
        $transferId = $this->getAttribute('wise_transfer_id');
        if ($transferId === null) {
            throw new WiseException('Cannot cancel a payout that has not been created in Wise.');
        }

        $remote = $this->profile()->transfers()->cancel((int) $transferId);

        return $this->applyRemoteStatus($remote->status, null, $remote, $dispatchEvents);
    }

    /**
     * Apply a Wise status observed via webhook or reconciliation, ordered by `occurred_at`. When
     * the timestamp is missing or not newer than the stored one, the authoritative transfer is
     * fetched rather than guessing. `$remote` short-circuits that fetch when already available.
     *
     * The status comparison and update run under a row lock so a stale observer can never let an
     * older status overwrite a newer one.
     */
    public function applyRemoteStatus(
        ?string $wiseStatus,
        ?DateTimeInterface $occurredAt = null,
        ?WiseTransferData $remote = null,
        bool $dispatchEvents = true,
    ): self {
        if ($wiseStatus === null || $wiseStatus === '') {
            return $this;
        }

        $occurredAt = $this->asCarbonImmutable($occurredAt);
        $stored = $this->getAttribute('wise_status_occurred_at');

        $fundingTimestamp = null;
        if ($occurredAt === null || ($stored !== null && ! $occurredAt->greaterThan($stored))) {
            $remote ??= $this->fetchRemoteTransfer();
            if ($remote === null) {
                return $this;
            }

            $wiseStatus = $remote->status;
            $occurredAt = CarbonImmutable::now();
        }

        $knownStatus = WiseTransferStatus::tryFrom($wiseStatus);
        if ($this->getAttribute('funded_at') === null
            && $knownStatus?->confirmsFundsArrived()
            && $remote !== null) {
            $fundingTimestamp = $this->fundingPaymentTimestamp();
        }

        $previous = null;
        $applied = false;

        DB::transaction(function () use ($wiseStatus, $occurredAt, $fundingTimestamp, $knownStatus, &$previous, &$applied): void {
            $fresh = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return;
            }

            $freshStored = $fresh->getAttribute('wise_status_occurred_at');
            if ($freshStored !== null && ! $occurredAt->greaterThan($freshStored)) {
                $this->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $freshPrevious = $fresh->getAttribute('wise_status');
            if ($freshPrevious === $wiseStatus && $freshStored !== null) {
                $this->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $previous = $freshPrevious;

            $fresh->setAttribute('wise_status', $wiseStatus);
            $fresh->setAttribute('wise_status_occurred_at', $occurredAt);

            if ($fresh->getAttribute('funded_at') === null && $knownStatus?->confirmsFundsArrived()) {
                $fresh->setAttribute('funded_at', $fundingTimestamp ?? $occurredAt);
            }

            $fresh->save();
            $this->setRawAttributes($fresh->getAttributes(), true);
            $applied = true;
        });

        if ($applied && $dispatchEvents) {
            $this->dispatchApplicationEvent(new TransferStatusUpdated($this, $previous, $wiseStatus));
            $this->dispatchTransitionEvents($wiseStatus);
        }

        return $this;
    }

    /**
     * Filter query by pending transfers.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', TransferStatus::FailedToCreate)
            ->where(function (Builder $query): void {
                $query->whereNull('wise_status')
                    ->orWhereNotIn('wise_status', [...WiseTransferStatus::failedValues(), WiseTransferStatus::OutgoingPaymentSent->value]);
            });
    }

    /**
     * Filter query by completed transfers.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('wise_status', WiseTransferStatus::OutgoingPaymentSent->value);
    }

    /**
     * Filter query by failed transfers.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('wise_status', WiseTransferStatus::failedValues())
                ->orWhere('status', TransferStatus::FailedToCreate);
        });
    }

    /**
     * Recently sent transfers (for late bounce-back/chargeback reconciliation).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSent(Builder $query, ?DateTimeInterface $since = null): Builder
    {
        $query->where('wise_status', WiseTransferStatus::OutgoingPaymentSent->value);

        if ($since !== null) {
            $query->where('wise_status_occurred_at', '>=', $since);
        }

        return $query;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'recipient_id' => 'integer',
            'wise_recipient_id' => 'integer',
            'wise_connection_id' => 'integer',
            'wise_transfer_id' => 'integer',
            'balance_transaction_id' => 'integer',
            'source_amount' => 'integer',
            'target_amount' => 'integer',
            'fee_amount' => 'integer',
            'rate' => 'decimal:8',
            'status' => TransferStatus::class,
            'funding_mode' => FundingMode::class,
            'funding_status' => FundingStatus::class,
            'funding_attempted_at' => 'immutable_datetime',
            'funded_at' => 'immutable_datetime',
            'wise_status_occurred_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }

    private function dispatchTransitionEvents(string $current): void
    {
        $status = WiseTransferStatus::tryFrom($current);

        if ($status === WiseTransferStatus::OutgoingPaymentSent) {
            $this->dispatchApplicationEvent(new TransferCompleted($this));
        }

        if ($status?->isFailed()) {
            $this->dispatchApplicationEvent(new TransferFailed($this));
        }

        if ($status === WiseTransferStatus::BouncedBack) {
            $this->dispatchApplicationEvent(new TransferReturned($this));
        }
    }

    private function dispatchApplicationEvent(object $event): void
    {
        app(WiseEventDispatcher::class)->dispatchApplicationEvent($event);
    }

    private function knownWiseStatus(): ?WiseTransferStatus
    {
        $status = $this->getAttribute('wise_status');

        return is_string($status) ? WiseTransferStatus::tryFrom($status) : null;
    }

    private function fetchRemoteTransfer(): ?WiseTransferData
    {
        $transferId = $this->getAttribute('wise_transfer_id');
        if ($transferId === null) {
            return null;
        }

        try {
            return $this->profile()->transfers()->find((int) $transferId);
        } catch (WiseApiError $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    private function fundingPaymentTimestamp(): ?DateTimeInterface
    {
        $transferId = $this->getAttribute('wise_transfer_id');
        if ($transferId === null) {
            return null;
        }

        try {
            $payments = $this->profile()->transfers()->payments((int) $transferId);
        } catch (WiseException) {
            return null;
        }

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

    private function asCarbonImmutable(?DateTimeInterface $occurredAt): ?CarbonImmutable
    {
        if ($occurredAt === null) {
            return null;
        }

        return $occurredAt instanceof CarbonImmutable
            ? $occurredAt
            : CarbonImmutable::instance($occurredAt);
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

<?php

namespace Owise\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Owise\Exceptions\IdempotencyConflict;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Exceptions\PermissionDenied;
use Owise\Exceptions\RecipientOwnershipMismatch;
use Owise\Exceptions\StrongCustomerAuthenticationRequired;
use Owise\Exceptions\TransferFundingOutcomeUnknown;
use Owise\Exceptions\TransferFundingRejected;
use Owise\Exceptions\TransferRequirementsMissing;
use Owise\Models\WiseTransfer;

/**
 * Async wrapper around the payout workflow. Unique per transfer so concurrent dispatches for the
 * same draft do not run the workflow twice.
 */
class SendPayout implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120, 300, 900];

    /**
     * The number of seconds after which the job's unique lock is released.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly WiseTransfer $transfer) {}

    /**
     * Resume the payout workflow.
     *
     * Permanent, non-retryable failures mark the job failed; everything else propagates so the
     * queue can retry it.
     */
    public function handle(): void
    {
        try {
            $this->transfer->resume();
        } catch (
            IdempotencyConflict|
            TransferRequirementsMissing|
            TransferFundingRejected|
            TransferFundingOutcomeUnknown|
            RecipientOwnershipMismatch|
            StrongCustomerAuthenticationRequired|
            PermissionDenied|
            InvalidArgumentException|
            InvalidConfiguration $e
        ) {
            $this->fail($e);

            return;
        }
    }

    /**
     * Get the job's unique identifier.
     */
    public function uniqueId(): string
    {
        return (string) $this->transfer->getKey();
    }
}

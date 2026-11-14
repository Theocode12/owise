<?php

namespace Owise\Exceptions;

use Owise\Models\WiseTransfer;

/**
 * Wise did not return a definitive funding result and no completed payment is visible yet.
 * The payout must not be funded again automatically.
 */
class TransferFundingOutcomeUnknown extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(public readonly WiseTransfer $transfer)
    {
        parent::__construct('Wise funding outcome is still unknown; the payout was not retried to avoid duplicate funding.');
    }
}

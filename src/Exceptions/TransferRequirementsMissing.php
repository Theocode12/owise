<?php

namespace Owise\Exceptions;

use Owise\Data\RequirementsForm;
use Owise\Models\WiseTransfer;

/**
 * Thrown when Wise's dynamic transfer-requirements form still requires caller input after the
 * supplied details were applied. The payout is left resumable and the caller may retry with
 * additional `transferDetails()` (and quote options if needed).
 */
class TransferRequirementsMissing extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message,
        public readonly RequirementsForm $form,
        public readonly WiseTransfer $transfer,
    ) {
        parent::__construct($message);
    }
}

<?php

namespace Owise\Exceptions;

/**
 * Thrown when Wise accepts a funding request but reports `REJECTED` (for example insufficient
 * funds). Wise's error code and message are preserved so callers can map them to a recovery path.
 */
class TransferFundingRejected extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
        parent::__construct($message);
    }
}

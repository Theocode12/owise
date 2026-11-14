<?php

namespace Owise\Exceptions;

use Owise\Models\WiseTransfer;

/**
 * Thrown when an idempotency key is reused for a payout with a different intent (amount, currency,
 * recipient or funding mode). The existing transfer is attached for inspection; it must never be
 * returned silently as the result of an unrelated payout.
 */
class IdempotencyConflict extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message,
        public readonly WiseTransfer $existing,
    ) {
        parent::__construct($message);
    }
}

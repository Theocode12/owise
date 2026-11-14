<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseTransfer;

/**
 * Dispatched when Wise reports `bounced_back`. Non-terminal: Wise documents that the transfer may
 * return to `processing` or later become `funds_refunded`.
 */
class TransferReturned
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly WiseTransfer $transfer) {}
}

<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseTransfer;

/**
 * Dispatched only for terminal failure states (`cancelled`, `funds_refunded`, `charged_back`) and
 * the local `failed_to_create` state. `bounced_back` is non-terminal and uses TransferReturned.
 */
class TransferFailed
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly WiseTransfer $transfer) {}
}

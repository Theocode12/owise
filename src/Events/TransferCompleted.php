<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseTransfer;

/**
 * Wise has sent the payment (`outgoing_payment_sent`). This means Wise dispatched the money, not
 * that the recipient bank credited it; later bounce-back/chargeback states remain possible.
 */
class TransferCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly WiseTransfer $transfer) {}
}

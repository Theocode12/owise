<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseTransfer;

class TransferFunded
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly WiseTransfer $transfer) {}
}

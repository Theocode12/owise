<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseTransfer;

/**
 * Dispatched when Wise reports active cases for a transfer. The transfer is flagged in its meta.
 */
class TransferNeedsAttention
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<array-key, mixed>  $cases
     */
    public function __construct(
        public readonly WiseTransfer $transfer,
        public readonly array $cases,
    ) {}
}

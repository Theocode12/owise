<?php

namespace Owise\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Owise\Models\WiseRecipient;

class RecipientCreated
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly WiseRecipient $recipient) {}
}

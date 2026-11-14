<?php

namespace Owise\Events;

use Carbon\CarbonImmutable;
use Owise\Webhooks\Payload;

final readonly class TransferRefunded
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Payload $payload,
        public ?string $transferId,
        public ?CarbonImmutable $occurredAt,
    ) {}
}

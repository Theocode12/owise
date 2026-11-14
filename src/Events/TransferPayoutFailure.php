<?php

namespace Owise\Events;

use Carbon\CarbonImmutable;
use Owise\Webhooks\Payload;

final readonly class TransferPayoutFailure
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Payload $payload,
        public ?string $transferId,
        public ?string $failureReason,
        public ?CarbonImmutable $occurredAt,
    ) {}
}

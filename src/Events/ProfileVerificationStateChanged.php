<?php

namespace Owise\Events;

use Carbon\CarbonImmutable;
use Owise\Webhooks\Payload;

final readonly class ProfileVerificationStateChanged
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Payload $payload,
        public ?string $profileId,
        public ?string $previous,
        public ?string $current,
        public ?CarbonImmutable $occurredAt,
    ) {}
}

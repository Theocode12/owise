<?php

namespace Owise\Events;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Owise\Webhooks\Payload;

final readonly class BalanceUpdated
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Payload $payload,
        public ?string $balanceId,
        public ?string $profileId,
        public ?string $stepId,
        public ?CarbonImmutable $occurredAt,
        public ?Money $amount = null,
        public ?string $currency = null,
        public ?Money $postTransactionBalance = null,
    ) {}
}

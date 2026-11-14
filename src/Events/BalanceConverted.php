<?php

namespace Owise\Events;

use Brick\Money\Money;
use Owise\Data\BalanceMovement;

final readonly class BalanceConverted
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public BalanceMovement $movement,
        public ?Money $sourceAmount = null,
        public ?Money $targetAmount = null,
        public ?string $quoteId = null,
        public ?int $profileId = null,
    ) {}
}

<?php

namespace Owise\Concerns;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Owise\Builders\PayoutBuilder;
use Owise\Exceptions\InvalidConfiguration;
use Owise\Models\WiseTransfer;

/**
 * Allow a payable model to send payouts and track Wise transfers.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait SendsPayouts
{
    /**
     * Begin a payout from this payable to a Wise recipient.
     */
    public function payout(BrickMoney|int|float|string $amount, ?string $currency = null): PayoutBuilder
    {
        return new PayoutBuilder($this, $amount, $currency);
    }

    /**
     * Get the Wise transfers for the payable.
     *
     * @return MorphMany<WiseTransfer, $this>
     */
    public function wiseTransfers(): MorphMany
    {
        return $this->morphMany($this->wiseTransferModelClass(), 'payable');
    }

    /** @return class-string<WiseTransfer> */
    private function wiseTransferModelClass(): string
    {
        $class = config('wise.models.transfer', WiseTransfer::class);
        if (! is_string($class) || ! is_a($class, WiseTransfer::class, true)) {
            throw new InvalidConfiguration('wise.models.transfer must be a WiseTransfer model class.');
        }

        return $class;
    }
}

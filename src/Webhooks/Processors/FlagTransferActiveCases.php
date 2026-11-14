<?php

namespace Owise\Webhooks\Processors;

use Owise\Events\TransferActiveCases;
use Owise\Events\TransferNeedsAttention;
use Owise\Models\WiseTransfer;
use Owise\Support\WiseEventDispatcher;

/** Persists active cases on a transfer and publishes the attention event. */
class FlagTransferActiveCases
{
    public function handle(TransferActiveCases $event): void
    {
        $transfer = WiseTransfer::query()->where('wise_transfer_id', $event->transferId)->first();
        if ($transfer === null) {
            return;
        }

        $meta = $transfer->meta ?? [];
        $meta['active_cases'] = $event->cases;
        $transfer->forceFill(['meta' => $meta])->save();

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferNeedsAttention($transfer, $event->cases));
    }
}

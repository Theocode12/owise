<?php

namespace Owise\Webhooks\Processors;

use Owise\Events\TransferPayoutFailure;
use Owise\Models\WiseTransfer;

/** Records a payout failure without changing the transfer status. */
class RecordTransferPayoutFailure
{
    public function handle(TransferPayoutFailure $event): void
    {
        $transfer = WiseTransfer::query()->where('wise_transfer_id', $event->transferId)->first();
        if ($transfer === null) {
            return;
        }

        $transfer->forceFill(['failure_reason' => $event->failureReason])->save();
    }
}

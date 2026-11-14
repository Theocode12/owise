<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\TransferPayoutFailure as TransferPayoutFailureEvent;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;
use Owise\Webhooks\Processors\RecordTransferPayoutFailure as RecordTransferPayoutFailureProcessor;

/**
 * Handle incoming `transfers#payout-failure` webhooks.
 */
class TransferPayoutFailure implements WebhookHandler
{
    /**
     * Handle the transfer payout failure payload.
     */
    public function handle(Payload $payload): void
    {
        $event = new TransferPayoutFailureEvent(
            payload: $payload,
            transferId: $payload->resourceId ?? Payload::value($payload->data, 'transfer_id', 'id'),
            failureReason: Payload::value($payload->data, 'failure_reason', 'failureReason', 'reason'),
            occurredAt: $payload->occurredAt,
        );

        app(RecordTransferPayoutFailureProcessor::class)->handle($event);
        app(WiseEventDispatcher::class)->dispatchApplicationEvent($event);
    }
}

<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\TransferRefunded as TransferRefundedEvent;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;

/**
 * Handle incoming `transfers#refund` webhooks.
 */
class TransferRefund implements WebhookHandler
{
    /**
     * Handle the transfer refund payload.
     */
    public function handle(Payload $payload): void
    {
        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new TransferRefundedEvent(
            payload: $payload,
            transferId: $payload->resourceId ?? Payload::value($payload->data, 'transfer_id', 'id'),
            occurredAt: $payload->occurredAt,
        ));
    }
}

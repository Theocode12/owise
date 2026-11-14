<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\TransferStateChanged as TransferStateChangedEvent;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;
use Owise\Webhooks\Processors\SyncTransferState as SyncTransferStateProcessor;

/**
 * Handle incoming `transfers#state-change` webhooks.
 */
class TransferStateChanged implements WebhookHandler
{
    /**
     * Handle the transfer state change payload.
     */
    public function handle(Payload $payload): void
    {
        $data = $payload->data;

        $event = new TransferStateChangedEvent(
            payload: $payload,
            previous: Payload::value($data, 'previous_state', 'previousState'),
            current: Payload::value($data, 'current_state', 'currentState', 'status'),
            transferId: $payload->resourceId ?? Payload::value($data, 'transfer_id', 'id'),
            occurredAt: $payload->occurredAt,
        );

        app(SyncTransferStateProcessor::class)->handle($event);
        app(WiseEventDispatcher::class)->dispatchApplicationEvent($event);
    }
}

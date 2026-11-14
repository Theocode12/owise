<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\TransferActiveCases as TransferActiveCasesEvent;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;
use Owise\Webhooks\Processors\FlagTransferActiveCases as FlagTransferActiveCasesProcessor;

/**
 * Handle incoming `transfers#active-cases` webhooks.
 */
class TransferActiveCases implements WebhookHandler
{
    /**
     * Handle the transfer active cases payload.
     */
    public function handle(Payload $payload): void
    {
        $cases = $payload->data['active_cases'] ?? $payload->data['cases'] ?? [];

        $event = new TransferActiveCasesEvent(
            payload: $payload,
            transferId: $payload->resourceId ?? Payload::value($payload->data, 'transfer_id', 'id'),
            cases: is_array($cases) ? $cases : [],
        );

        app(FlagTransferActiveCasesProcessor::class)->handle($event);
        app(WiseEventDispatcher::class)->dispatchApplicationEvent($event);
    }
}

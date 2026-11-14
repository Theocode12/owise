<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\BalanceUpdated;
use Owise\Support\Payload as SupportPayload;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;

/**
 * Handle incoming `balances#update` webhooks.
 */
class BalanceUpdate implements WebhookHandler
{
    /**
     * Handle the balance update payload.
     */
    public function handle(Payload $payload): void
    {
        $currency = isset($payload->data['currency']) && is_string($payload->data['currency'])
            ? $payload->data['currency']
            : null;

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new BalanceUpdated(
            payload: $payload,
            balanceId: $payload->resourceId ?? Payload::value($payload->data, 'balance_id', 'id'),
            profileId: $payload->profileId,
            stepId: $payload->stepId,
            occurredAt: $payload->occurredAt,
            amount: SupportPayload::money($payload->data, 'amount', $currency),
            currency: $currency,
            postTransactionBalance: SupportPayload::money($payload->data, 'post_transaction_balance_amount', $currency),
        ));
    }
}

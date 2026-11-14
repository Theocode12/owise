<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\BalanceCredited;
use Owise\Support\Payload as SupportPayload;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;

/**
 * Legacy `balances#credit` event (deprecated by Wise, superseded by
 * `balances#update`). Still handled for existing subscriptions.
 */
class BalanceCredit implements WebhookHandler
{
    /**
     * Handle the legacy balance credit payload.
     */
    public function handle(Payload $payload): void
    {
        $currency = isset($payload->data['currency']) && is_string($payload->data['currency'])
            ? $payload->data['currency']
            : null;

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new BalanceCredited(
            payload: $payload,
            balanceId: $payload->resourceId ?? Payload::value($payload->data, 'balance_id', 'id'),
            profileId: $payload->profileId,
            occurredAt: $payload->occurredAt,
            amount: SupportPayload::money($payload->data, 'amount', $currency),
            currency: $currency,
            postTransactionBalance: SupportPayload::money($payload->data, 'post_transaction_balance_amount', $currency),
        ));
    }
}

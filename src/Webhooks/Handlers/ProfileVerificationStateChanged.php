<?php

namespace Owise\Webhooks\Handlers;

use Owise\Contracts\WebhookHandler;
use Owise\Events\ProfileVerificationStateChanged as ProfileVerificationStateChangedEvent;
use Owise\Support\WiseEventDispatcher;
use Owise\Webhooks\Payload;

/**
 * Handle incoming `profiles#verification-state-change` webhooks.
 */
class ProfileVerificationStateChanged implements WebhookHandler
{
    /**
     * Handle the profile verification payload.
     */
    public function handle(Payload $payload): void
    {
        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new ProfileVerificationStateChangedEvent(
            payload: $payload,
            profileId: $payload->profileId,
            previous: Payload::value($payload->data, 'previous_state', 'previousState'),
            current: Payload::value($payload->data, 'current_state', 'currentState', 'status'),
            occurredAt: $payload->occurredAt,
        ));
    }
}

<?php

namespace Owise\Events;

use Owise\Webhooks\Payload;

final readonly class WebhookReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(public Payload $payload) {}
}

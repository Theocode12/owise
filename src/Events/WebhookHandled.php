<?php

namespace Owise\Events;

use Owise\Webhooks\Payload;

final readonly class WebhookHandled
{
    /**
     * Create a new event instance.
     */
    public function __construct(public Payload $payload) {}
}

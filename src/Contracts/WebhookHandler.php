<?php

namespace Owise\Contracts;

use Owise\Webhooks\Payload;

interface WebhookHandler
{
    /**
     * Handle the given webhook payload.
     */
    public function handle(Payload $payload): void;
}

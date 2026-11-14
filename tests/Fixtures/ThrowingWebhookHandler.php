<?php

namespace Owise\Tests\Fixtures;

use Owise\Contracts\WebhookHandler;
use Owise\Webhooks\Payload;
use RuntimeException;

class ThrowingWebhookHandler implements WebhookHandler
{
    public function handle(Payload $payload): void
    {
        throw new RuntimeException('Temporary handler failure.');
    }
}

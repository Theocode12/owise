<?php

namespace Owise\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Owise\Webhooks\Payload;
use Owise\Webhooks\WebhookProcessor;

class ProcessWiseWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The backoff intervals between attempts in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public ?string $deliveryId = null,
        public bool $isTest = false,
    ) {}

    /**
     * Process the webhook payload.
     */
    public function handle(WebhookProcessor $processor): void
    {
        $processor->process(Payload::fromRequest($this->payload, $this->deliveryId, $this->isTest));
    }
}

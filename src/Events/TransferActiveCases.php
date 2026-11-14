<?php

namespace Owise\Events;

use Owise\Webhooks\Payload;

final readonly class TransferActiveCases
{
    /**
     * Create a new event instance.
     *
     * @param  array<array-key, mixed>  $cases
     */
    public function __construct(public Payload $payload, public ?string $transferId, public array $cases) {}
}

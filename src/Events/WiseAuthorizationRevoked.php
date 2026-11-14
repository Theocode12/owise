<?php

namespace Owise\Events;

use Owise\Models\WiseAuthorization;

final readonly class WiseAuthorizationRevoked
{
    /**
     * Create a new event instance.
     */
    public function __construct(public WiseAuthorization $authorization) {}
}

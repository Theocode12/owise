<?php

namespace Owise\Exceptions;

use Throwable;

class ConnectionFailed extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message,
        public readonly ?string $correlationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

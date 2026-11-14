<?php

namespace Owise\Exceptions;

use Throwable;

class InvalidRegistrationCode extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

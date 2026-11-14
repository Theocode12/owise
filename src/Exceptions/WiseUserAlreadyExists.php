<?php

namespace Owise\Exceptions;

use Throwable;

class WiseUserAlreadyExists extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);
    }
}

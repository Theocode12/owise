<?php

namespace Owise\Exceptions;

class RecipientOwnershipMismatch extends WiseException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = 'The Wise recipient does not belong to this payable.')
    {
        parent::__construct($message);
    }
}

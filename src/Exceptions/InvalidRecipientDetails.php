<?php

namespace Owise\Exceptions;

/** Wise rejected one or more of the recipient's submitted fields. */
class InvalidRecipientDetails extends WiseException
{
    /**
     * Create a new exception instance.
     *
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(
        public readonly array $fieldErrors,
        WiseApiError $previous,
    ) {
        parent::__construct($previous->getMessage(), $previous->getCode(), $previous);
    }
}

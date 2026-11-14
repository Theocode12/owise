<?php

namespace Owise\Exceptions;

use Throwable;

class WiseApiError extends WiseException
{
    /**
     * Create a new exception instance.
     *
     * @param  list<array{code: ?string, message: ?string, path: ?string, arguments: array<array-key, mixed>}>  $errors
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly ?string $traceId = null,
        public readonly ?string $correlationId = null,
        public readonly array $raw = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Determine if the error contains the given code.
     */
    public function hasErrorCode(string $code): bool
    {
        foreach ($this->errors as $error) {
            if ($error['code'] === $code) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Owise\Exceptions;

use Throwable;

class RateLimited extends WiseApiError
{
    /**
     * Create a new exception instance.
     *
     * @param  list<array{code: ?string, message: ?string, path: ?string, arguments: array<array-key, mixed>}>  $errors
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        string $message,
        int $status,
        public readonly ?int $retryAfter = null,
        array $errors = [],
        ?string $traceId = null,
        ?string $correlationId = null,
        array $raw = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errors, $traceId, $correlationId, $raw, $previous);
    }
}

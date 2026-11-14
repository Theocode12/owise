<?php

namespace Owise\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Keep the last request and response seen by one client's transport.
 *
 * Requests are synchronous, so the last response always belongs to the call being mapped.
 */
final class ResponseRecorder
{
    /**
     * The last recorded correlation id.
     */
    private ?string $correlationId = null;

    /**
     * The last recorded response.
     */
    private ?ResponseInterface $response = null;

    /**
     * Clear the recorded request and response.
     */
    public function reset(): void
    {
        $this->correlationId = null;
        $this->response = null;
    }

    /**
     * Record the correlation id of the outgoing request.
     */
    public function recordRequest(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    /**
     * Record the incoming response.
     */
    public function recordResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * Get the last recorded correlation id.
     */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Get the last recorded response.
     */
    public function response(): ?ResponseInterface
    {
        return $this->response;
    }
}

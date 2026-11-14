<?php

namespace Owise\Testing;

use Owise\Api\SimulationApi;

/**
 * Drive a single transfer through the sandbox simulation states in order.
 */
final readonly class TransferSimulation
{
    /**
     * Create a new transfer simulation for the given Wise transfer id.
     */
    public function __construct(private SimulationApi $api, private int $transferId) {}

    /**
     * Simulate `processing`.
     */
    public function processing(): self
    {
        return $this->state('processing');
    }

    /**
     * Simulate `funds_converted`.
     */
    public function fundsConverted(): self
    {
        return $this->state('funds_converted');
    }

    /**
     * Simulate `outgoing_payment_sent`.
     */
    public function outgoingPaymentSent(): self
    {
        return $this->state('outgoing_payment_sent');
    }

    /**
     * Simulate `bounced_back`.
     */
    public function bouncedBack(): self
    {
        return $this->state('bounced_back');
    }

    /**
     * Simulate `funds_refunded`.
     */
    public function fundsRefunded(): self
    {
        return $this->state('funds_refunded');
    }

    /**
     * Simulate an arbitrary supported status.
     */
    public function state(string $status): self
    {
        $this->api->transferState($this->transferId, $status);

        return $this;
    }
}

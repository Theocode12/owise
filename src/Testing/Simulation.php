<?php

namespace Owise\Testing;

use Brick\Money\Money;
use InvalidArgumentException;
use Owise\Api\SimulationApi;
use Owise\Data\BalanceTopUp;
use Owise\Data\Transfer;
use Owise\Models\WiseTransfer;

/**
 * Fluent sandbox simulation over {@see SimulationApi}.
 *
 * ```php
 * Wise::simulate()->transfer($transfer)->processing()->fundsConverted()->outgoingPaymentSent();
 * ```
 */
final readonly class Simulation
{
    /**
     * Create a new simulation for a profile's simulation API.
     */
    public function __construct(private SimulationApi $api) {}

    /**
     * Start simulating a transfer by its Wise id or local {@see WiseTransfer} model.
     */
    public function transfer(int|WiseTransfer $transfer): TransferSimulation
    {
        if ($transfer instanceof WiseTransfer) {
            $id = $transfer->wise_transfer_id;

            if (! is_numeric($id) || (int) $id < 1) {
                throw new InvalidArgumentException('The WiseTransfer must have a positive wise_transfer_id before it can be simulated.');
            }

            $transfer = (int) $id;
        }

        if ($transfer < 1) {
            throw new InvalidArgumentException('A positive Wise transfer ID is required for simulation.');
        }

        return new TransferSimulation($this->api, $transfer);
    }

    /**
     * Simulate a single transfer state transition.
     */
    public function transferState(int $transferId, string $status): Transfer
    {
        return $this->api->transferState($transferId, $status);
    }

    /**
     * Simulate a balance top-up.
     */
    public function balanceTopUp(int $balanceId, Money $amount, string $channel = 'TRANSFER', ?int $profileId = null): BalanceTopUp
    {
        return $this->api->balanceTopUp($balanceId, $amount, $channel, $profileId);
    }
}

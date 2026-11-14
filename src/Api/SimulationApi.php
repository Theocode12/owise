<?php

namespace Owise\Api;

use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use Owise\Account\Environment;
use Owise\Data\BalanceTopUp;
use Owise\Data\Transfer;
use Owise\Exceptions\SimulationNotAllowed;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Money;

/**
 * Simulate Wise transfers and balance top-ups in the sandbox.
 */
final class SimulationApi extends AccountApi
{
    /** The transfer statuses that can be simulated in order. @var list<string> */
    public const TRANSFER_STATUSES = ['processing', 'funds_converted', 'outgoing_payment_sent', 'bounced_back', 'funds_refunded'];

    /**
     * Simulate a transfer state transition.
     *
     * Transfer states must be walked in order (fund the transfer first):
     * processing → funds_converted → outgoing_payment_sent → bounced_back → funds_refunded.
     *
     * @param  int  $transferId  The transfer ID.
     * @param  string  $status  The status to simulate.
     * @return Transfer The simulated transfer.
     *
     * @throws InvalidArgumentException
     * @throws SimulationNotAllowed
     * @throws WiseApiError
     */
    public function transferState(int $transferId, string $status): Transfer
    {
        $this->ensureSandbox();

        if (! in_array($status, self::TRANSFER_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Unsupported simulated transfer status '{$status}'. Expected one of: ".implode(', ', self::TRANSFER_STATUSES).'.',
            );
        }

        return Transfer::fromArray($this->client->requestOperation('simulation.transfer-state', [
            'transferId' => $transferId,
            'status' => $status,
        ]));
    }

    /**
     * Simulate a balance top-up.
     *
     * @param  int  $balanceId  The balance ID.
     * @param  BrickMoney  $amount  The top-up amount.
     * @param  string  $channel  The top-up channel.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceTopUp The top-up result.
     *
     * @throws SimulationNotAllowed
     * @throws WiseApiError
     */
    public function balanceTopUp(int $balanceId, BrickMoney $amount, string $channel = 'TRANSFER', ?int $profileId = null): BalanceTopUp
    {
        $this->ensureSandbox();

        return BalanceTopUp::fromArray($this->client->requestOperation('simulation.balance-top-up', body: [
            'profileId' => $this->profileId($profileId),
            'balanceId' => $balanceId,
            'currency' => $amount->getCurrency()->getCurrencyCode(),
            'amount' => Money::toWiseNumber($amount),
            'channel' => $channel,
        ]));
    }

    /**
     * Ensure the current account uses the sandbox environment.
     *
     *
     * @throws SimulationNotAllowed
     */
    private function ensureSandbox(): void
    {
        if ($this->context->environment !== Environment::Sandbox) {
            throw new SimulationNotAllowed(
                "Wise simulation endpoints are sandbox only; account '{$this->context->name}' uses the {$this->context->environment->value} environment.",
            );
        }
    }
}

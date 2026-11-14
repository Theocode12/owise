<?php

namespace Owise\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Owise\Enums\FundingMode;
use Owise\Enums\FundingStatus;
use Owise\Enums\TransferStatus;
use Owise\Models\WiseTransfer;

/**
 * @extends Factory<WiseTransfer>
 */
class WiseTransferFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<WiseTransfer>
     */
    protected $model = WiseTransfer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_id' => null,
            'wise_recipient_id' => null,
            'account' => null,
            'wise_connection_id' => null,
            'profile_id' => 101,
            'customer_transaction_id' => (string) Str::uuid(),
            'idempotency_key' => null,
            'wise_quote_id' => null,
            'wise_transfer_id' => null,
            'status' => TransferStatus::Draft->value,
            'wise_status' => null,
            'funding_mode' => FundingMode::Manual->value,
            'funding_status' => null,
            'source_currency' => 'GBP',
            'source_amount' => 10000,
            'target_currency' => 'EUR',
            'target_amount' => 8550,
            'fee_amount' => null,
            'rate' => null,
            'reference' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * A draft payout that has not been quoted yet.
     *
     * @return $this
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Draft->value,
            'wise_status' => null,
            'wise_transfer_id' => null,
        ]);
    }

    /**
     * A payout with a quote that has not yet been created in Wise.
     *
     * @return $this
     */
    public function quoted(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Quoted->value,
            'wise_quote_id' => (string) Str::uuid(),
            'wise_transfer_id' => null,
            'wise_status' => null,
        ]);
    }

    /**
     * A transfer created in Wise and waiting for its next state transition.
     *
     * @return $this
     */
    public function created(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Created->value,
            'wise_transfer_id' => $this->faker->unique()->numberBetween(10000000, 99999999),
            'wise_status' => WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING,
        ]);
    }

    /**
     * A payout that failed before a transfer was created in Wise.
     *
     * @return $this
     */
    public function failedToCreate(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::FailedToCreate->value,
            'wise_transfer_id' => null,
            'wise_status' => null,
            'failure_reason' => 'Transfer creation failed.',
        ]);
    }

    /**
     * A created payout parked in manual funding (awaiting funding).
     *
     * @return $this
     */
    public function awaitingFunding(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::AwaitingFunding->value,
            'wise_status' => WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING,
            'funding_mode' => FundingMode::Manual->value,
        ]);
    }

    /**
     * A completed payout whose funds were sent.
     *
     * @return $this
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Created->value,
            'wise_status' => WiseTransfer::WISE_STATUS_OUTGOING_PAYMENT_SENT,
            'funding_status' => FundingStatus::Completed->value,
            'funded_at' => now(),
        ]);
    }

    /**
     * A failed payout (cancelled in Wise).
     *
     * @return $this
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Created->value,
            'wise_status' => WiseTransfer::WISE_STATUS_CANCELLED,
        ]);
    }

    /**
     * A created API-funded payout whose funding was rejected.
     *
     * @return $this
     */
    public function fundingRejected(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Created->value,
            'wise_status' => WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING,
            'funding_mode' => FundingMode::Api->value,
            'funding_status' => FundingStatus::Rejected->value,
            'failure_reason' => 'Wise rejected the transfer funding.',
        ]);
    }

    /**
     * A created API-funded payout whose funding outcome is unknown.
     *
     * @return $this
     */
    public function fundingUnknown(): static
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Created->value,
            'wise_status' => WiseTransfer::WISE_STATUS_INCOMING_PAYMENT_WAITING,
            'funding_mode' => FundingMode::Api->value,
            'funding_status' => FundingStatus::Unknown->value,
        ]);
    }
}

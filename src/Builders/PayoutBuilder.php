<?php

namespace Owise\Builders;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use Owise\Data\Quote;
use Owise\Enums\FundingMode;
use Owise\Jobs\SendPayout;
use Owise\Models\WiseRecipient;
use Owise\Models\WiseTransfer;
use Owise\Services\PayoutService;

/**
 * Fluent builder for a payout (quote → transfer → fund).
 */
class PayoutBuilder
{
    use Conditionable;

    /** The payout recipient. */
    public WiseRecipient|int|null $recipient = null;

    /** The source currency code. */
    public ?string $sourceCurrency = null;

    /** The flag indicating whether the amount is the source amount. */
    public bool $amountIsSource = false;

    /** The payment reference. */
    public ?string $reference = null;

    /** The idempotency key for the payout. */
    public ?string $idempotencyKey = null;

    /**
     * The payout metadata.
     *
     * @var array<string, mixed>
     */
    public array $meta = [];

    /** The funding mode for the payout. */
    public ?FundingMode $fundingMode = null;

    /**
     * The transfer requirement details.
     *
     * @var array<string, mixed>
     */
    public array $transferDetails = [];

    /** The originator legal entity type. */
    public ?string $originatorLegalEntityType = null;

    /** The quote-level transfer nature. */
    public ?string $transferNature = null;

    /**
     * The additional Wise quote request fields.
     *
     * @var array<string, mixed>
     */
    public array $quoteOptions = [];

    /**
     * Create a new payout builder instance.
     */
    public function __construct(
        public readonly Model $payable,
        public readonly BrickMoney|int|float|string $amount,
        public readonly ?string $currency = null,
    ) {}

    /**
     * Set the payout recipient.
     *
     * @return $this
     */
    public function to(WiseRecipient|int $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Set the source currency.
     *
     * @return $this
     */
    public function from(string $sourceCurrency): static
    {
        $this->sourceCurrency = $sourceCurrency;

        return $this;
    }

    /**
     * Set whether the amount is the source amount.
     *
     * @return $this
     */
    public function amountIsSource(bool $amountIsSource = true): static
    {
        $this->amountIsSource = $amountIsSource;

        return $this;
    }

    /**
     * Set the payment reference.
     *
     * @return $this
     */
    public function reference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Set the idempotency key.
     *
     * Required for `send()` / `sendLater()`. Reuse the same key to retry or resume the same
     * payout; use a new key for a new payout.
     *
     * @return $this
     */
    public function idempotencyKey(string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    /**
     * Set the payout metadata.
     *
     * @param  array<string, mixed>  $meta
     * @return $this
     */
    public function withMeta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Override the account funding mode: `fund(true)` for API funding, `fund(false)` for manual.
     *
     * @return $this
     */
    public function fund(bool $api = true): static
    {
        $this->fundingMode = $api ? FundingMode::Api : FundingMode::Manual;

        return $this;
    }

    /**
     * Transfer-requirement details such as `transferPurpose` and `sourceOfFunds`.
     *
     * @param  array<string, mixed>  $details
     * @return $this
     */
    public function transferDetails(array $details): static
    {
        $this->transferDetails = $details;

        return $this;
    }

    /**
     * Set the originator legal entity type.
     *
     * @return $this
     */
    public function originatorLegalEntityType(string $type): static
    {
        $this->originatorLegalEntityType = $type;

        return $this;
    }

    /**
     * Quote-level transfer nature, required for some BRL routes.
     *
     * @return $this
     */
    public function transferNature(string $nature): static
    {
        $this->transferNature = $nature;

        return $this;
    }

    /**
     * Set additional Wise quote request fields, such as payment metadata or creation context.
     * `preferredPayIn` may be overridden here; otherwise it follows the payout funding mode.
     *
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function quoteOptions(array $options): static
    {
        $this->quoteOptions = $options;

        return $this;
    }

    /**
     * Build a quote without persisting anything. The quote is never reused by `send()`.
     */
    public function preview(): Quote
    {
        return app(PayoutService::class)->preview($this);
    }

    /**
     * Send the payout.
     *
     * Requires an idempotency key. Reusing the key resumes the same payout; a new key starts a
     * new payout.
     */
    public function send(): WiseTransfer
    {
        return app(PayoutService::class)->send($this);
    }

    /**
     * Persist the draft and dispatch the workflow to the queue.
     *
     * Requires an idempotency key. Reusing the key resumes the same payout; a new key starts a
     * new payout.
     */
    public function sendLater(?string $queue = null): WiseTransfer
    {
        $transfer = app(PayoutService::class)->createDraft($this);

        $queue === null ? SendPayout::dispatch($transfer) : SendPayout::dispatch($transfer)->onQueue($queue);

        return $transfer;
    }
}

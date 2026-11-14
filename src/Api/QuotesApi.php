<?php

namespace Owise\Api;

use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use Owise\Data\Quote;
use Owise\Data\QuoteOptions;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Money;

/**
 * Create Wise quotes for the current profile.
 */
final class QuotesApi extends AccountApi
{
    /**
     * Create a quote for the profile.
     *
     * Provide exactly one of `$sourceAmount` or `$targetAmount`.
     *
     * @param  string  $sourceCurrency  The source currency code.
     * @param  string  $targetCurrency  The target currency code.
     * @param  BrickMoney|int|float|string|null  $sourceAmount  The fixed source amount, if any.
     * @param  BrickMoney|int|float|string|null  $targetAmount  The fixed target amount, if any.
     * @param  int|null  $targetAccount  The recipient ID, if any.
     * @param  string|null  $payOut  The payout type, if any.
     * @param  string|null  $preferredPayIn  The preferred pay-in method, if any.
     * @param  string|null  $transferNature  The transfer nature, if any.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @param  array<string, mixed>  $options  Additional quote fields (`paymentMetadata`, `creationContext`, pay-in IDs, or pricing configuration).
     * @return Quote The created quote.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function create(
        string $sourceCurrency,
        string $targetCurrency,
        BrickMoney|int|float|string|null $sourceAmount = null,
        BrickMoney|int|float|string|null $targetAmount = null,
        ?int $targetAccount = null,
        ?string $payOut = null,
        ?string $preferredPayIn = null,
        ?string $transferNature = null,
        ?int $profileId = null,
        array $options = [],
    ): Quote {
        if (($sourceAmount === null) === ($targetAmount === null)) {
            throw new InvalidArgumentException('Provide exactly one of sourceAmount or targetAmount when creating a Wise quote.');
        }

        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);

        if ($sourceAmount instanceof BrickMoney && $sourceAmount->getCurrency()->getCurrencyCode() !== $sourceCurrency) {
            throw new InvalidArgumentException(
                "sourceAmount currency must match sourceCurrency ({$sourceCurrency}).",
            );
        }

        if ($targetAmount instanceof BrickMoney && $targetAmount->getCurrency()->getCurrencyCode() !== $targetCurrency) {
            throw new InvalidArgumentException(
                "targetAmount currency must match targetCurrency ({$targetCurrency}).",
            );
        }

        $quoteOptions = QuoteOptions::fromArray($options)->withTransferNature($transferNature);
        $optionPreferredPayIn = $quoteOptions->preferredPayIn();
        if ($preferredPayIn !== null && $optionPreferredPayIn !== null && $preferredPayIn !== $optionPreferredPayIn) {
            throw new InvalidArgumentException('preferredPayIn conflicts with options.preferredPayIn.');
        }
        $preferredPayIn ??= $optionPreferredPayIn;

        $body = array_filter([
            'sourceCurrency' => $sourceCurrency,
            'targetCurrency' => $targetCurrency,
            'sourceAmount' => $sourceAmount === null ? null : Money::toWiseNumber($sourceAmount),
            'targetAmount' => $targetAmount === null ? null : Money::toWiseNumber($targetAmount),
            'targetAccount' => $targetAccount,
            'payOut' => $payOut,
            'preferredPayIn' => $preferredPayIn,
        ], fn (mixed $value): bool => $value !== null);

        foreach ($quoteOptions->toArray(includePreferredPayIn: false) as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        return Quote::fromArray(
            $this->client->requestOperation('quotes.create', ['profileId' => $this->profileId($profileId)], body: $body),
        );
    }

    /**
     * Get a quote by its ID.
     *
     * @param  string  $quoteId  The quote ID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Quote The quote.
     *
     * @throws WiseApiError
     */
    public function find(string $quoteId, ?int $profileId = null): Quote
    {
        return Quote::fromArray($this->client->requestOperation('quotes.find', [
            'profileId' => $this->profileId($profileId),
            'quoteId' => $quoteId,
        ]));
    }

    /**
     * Update a quote with its recipient and payout.
     *
     * @param  string  $quoteId  The quote ID.
     * @param  int  $targetAccount  The recipient ID.
     * @param  string|null  $payOut  The payout type, if any.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Quote The updated quote.
     *
     * @throws WiseApiError
     */
    public function update(string $quoteId, int $targetAccount, ?string $payOut = null, ?int $profileId = null): Quote
    {
        $body = array_filter(
            ['targetAccount' => $targetAccount, 'payOut' => $payOut],
            fn (mixed $value): bool => $value !== null,
        );

        return Quote::fromArray($this->client->requestOperation(
            'quotes.update',
            [
                'profileId' => $this->profileId($profileId),
                'quoteId' => $quoteId,
            ],
            body: $body,
            headers: ['Content-Type' => 'application/merge-patch+json'],
        ));
    }
}

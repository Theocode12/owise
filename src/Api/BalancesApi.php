<?php

namespace Owise\Api;

use Brick\Money\Money as BrickMoney;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Owise\Data\Balance;
use Owise\Data\BalanceCapacity;
use Owise\Data\BalanceMovement;
use Owise\Data\Quote;
use Owise\Data\Statement;
use Owise\Data\TotalFunds;
use Owise\Events\BalanceConverted;
use Owise\Exceptions\BalanceNotActionable;
use Owise\Exceptions\MissingProfile;
use Owise\Exceptions\WiseApiError;
use Owise\Support\Idempotency;
use Owise\Support\Money;
use Owise\Support\Payload;
use Owise\Support\WiseEventDispatcher;

/**
 * Manage Wise balances for the current profile.
 */
final class BalancesApi extends AccountApi
{
    /** The idempotence header for balance write operations. @var string */
    private const IDEMPOTENCE_HEADER = 'X-idempotence-uuid';

    /**
     * List the balances for the profile.
     *
     * @param  string|list<string>  $types  The balance types to include.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return list<Balance> The balances.
     *
     * @throws WiseApiError
     */
    public function list(string|array $types = ['STANDARD'], ?int $profileId = null): array
    {
        $payload = $this->client->requestOperation('balances.list', [
            'profileId' => $this->profileId($profileId),
        ], query: [
            'types' => is_array($types) ? implode(',', $types) : $types,
        ]);

        return array_map(Balance::fromArray(...), Payload::arrays($payload));
    }

    /**
     * Get all balances for the profile as a collection.
     *
     * @param  string|null  $type  The balance type to include.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Collection<int, Balance> The balances.
     *
     * @throws WiseApiError
     */
    public function all(?string $type = 'STANDARD', ?int $profileId = null): Collection
    {
        return new Collection($this->list($type ?? 'STANDARD', $profileId));
    }

    /**
     * Get the balance for the given currency.
     *
     * Searches both STANDARD and SAVINGS balances.
     *
     * @param  string  $currency  The currency code.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Balance|null The balance, if found.
     *
     * @throws WiseApiError
     */
    public function for(string $currency, ?int $profileId = null): ?Balance
    {
        $currency = strtoupper($currency);

        foreach ($this->list(['STANDARD', 'SAVINGS'], $profileId) as $balance) {
            if (strtoupper($balance->currency) === $currency) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * Get a balance by its ID.
     *
     * @param  int  $balanceId  The balance ID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Balance The balance.
     *
     * @throws WiseApiError
     */
    public function find(int $balanceId, ?int $profileId = null): Balance
    {
        return Balance::fromArray($this->client->requestOperation('balances.find', [
            'profileId' => $this->profileId($profileId),
            'balanceId' => $balanceId,
        ]));
    }

    /**
     * Open a new balance for the profile.
     *
     * Alias of create(). Wise requires a name for SAVINGS balances.
     *
     * @param  string  $currency  The currency code.
     * @param  string  $type  The balance type.
     * @param  string|null  $name  The balance name, required for SAVINGS.
     * @param  string|null  $idempotencyKey  The idempotency key for safe retries.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Balance The created balance.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function open(string $currency, string $type = 'STANDARD', ?string $name = null, ?string $idempotencyKey = null, ?int $profileId = null): Balance
    {
        return $this->create($currency, $idempotencyKey, $type, $name, $profileId);
    }

    /**
     * Create a new balance for the profile.
     *
     * @param  string  $currency  The currency code.
     * @param  string|null  $idempotencyKey  The idempotency key for safe retries.
     * @param  string  $type  The balance type.
     * @param  string|null  $name  The balance name, required for SAVINGS.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Balance The created balance.
     *
     * @throws InvalidArgumentException
     * @throws WiseApiError
     */
    public function create(string $currency, ?string $idempotencyKey = null, string $type = 'STANDARD', ?string $name = null, ?int $profileId = null): Balance
    {
        if (trim($currency) === '') {
            throw new InvalidArgumentException('A currency is required to open a balance.');
        }

        if (strtoupper($type) === 'SAVINGS' && ($name === null || trim($name) === '')) {
            throw new InvalidArgumentException('Wise requires a name when opening a SAVINGS balance.');
        }

        $body = array_filter(
            ['currency' => strtoupper($currency), 'type' => strtoupper($type), 'name' => $name],
            fn (mixed $value): bool => $value !== null,
        );

        return Balance::fromArray($this->client->requestOperation(
            'balances.create',
            ['profileId' => $this->profileId($profileId)],
            body: $body,
            headers: [self::IDEMPOTENCE_HEADER => Idempotency::forKey($idempotencyKey, $this->namespace($profileId))],
        ));
    }

    /**
     * Close a balance.
     *
     * The balance must be empty before Wise allows closing it.
     *
     * @param  int  $balanceId  The balance ID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     *
     * @throws WiseApiError
     */
    public function close(int $balanceId, ?int $profileId = null): void
    {
        $this->client->requestOperation('balances.close', [
            'profileId' => $this->profileId($profileId),
            'balanceId' => $balanceId,
        ]);
    }

    /**
     * Get the balance capacity for a currency.
     *
     * @param  string  $currency  The currency code.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceCapacity The balance capacity.
     *
     * @throws WiseApiError
     */
    public function capacity(string $currency, ?int $profileId = null): BalanceCapacity
    {
        return BalanceCapacity::fromArray($this->client->requestOperation(
            'balances.capacity',
            ['profileId' => $this->profileId($profileId)],
            query: ['currency' => strtoupper($currency)],
        ));
    }

    /**
     * Get the total funds held for a currency.
     *
     * @param  string  $currency  The currency code.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return TotalFunds The total funds.
     *
     * @throws WiseApiError
     */
    public function totalFunds(string $currency, ?int $profileId = null): TotalFunds
    {
        return TotalFunds::fromArray($this->client->requestOperation(
            'balances.total-funds',
            [
                'profileId' => $this->profileId($profileId),
                'currency' => strtoupper($currency),
            ],
        ));
    }

    /**
     * Preview a conversion between balances with a BALANCE payout quote.
     *
     * @param  BrickMoney  $amount  The source amount.
     * @param  string  $to  The target currency.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return Quote The conversion quote.
     *
     * @throws WiseApiError
     */
    public function convertPreview(BrickMoney $amount, string $to, ?int $profileId = null): Quote
    {
        return $this->quotes()->create(
            $amount->getCurrency()->getCurrencyCode(),
            $to,
            sourceAmount: $amount,
            payOut: 'BALANCE',
            profileId: $profileId,
        );
    }

    /**
     * Convert funds between balances.
     *
     * Creates a quote with payOut BALANCE, then executes via balance-movements. Pass an idempotency
     * key for safe retries; without one a random uuid is used and retries are NOT safe.
     *
     * @param  BrickMoney  $amount  The source amount.
     * @param  string  $to  The target currency.
     * @param  string|null  $idempotencyKey  The idempotency key for safe retries.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceMovement The balance movement.
     *
     * @throws WiseApiError
     */
    public function convert(BrickMoney $amount, string $to, ?string $idempotencyKey = null, ?int $profileId = null): BalanceMovement
    {
        $quote = $this->convertPreview($amount, $to, $profileId);

        $movement = $this->convertByQuote(
            $quote->id,
            Idempotency::forKey($idempotencyKey, $this->namespace($profileId)),
            $profileId,
        );

        app(WiseEventDispatcher::class)->dispatchApplicationEvent(new BalanceConverted(
            movement: $movement,
            sourceAmount: $movement->sourceAmount ?? $amount,
            targetAmount: $movement->targetAmount,
            quoteId: $quote->id,
            profileId: $this->profileId($profileId),
        ));

        return $movement;
    }

    /**
     * Convert funds for a pre-created BALANCE quote.
     *
     * Low-level conversion execution. Prefer convert() which creates the quote for you.
     *
     * @param  string  $quoteId  The quote ID.
     * @param  string  $idempotencyUuid  The idempotency UUID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceMovement The balance movement.
     *
     * @throws WiseApiError
     */
    public function convertByQuote(string $quoteId, string $idempotencyUuid, ?int $profileId = null): BalanceMovement
    {
        return $this->movement(['quoteId' => $quoteId], Idempotency::assertValid($idempotencyUuid, self::IDEMPOTENCE_HEADER), $profileId);
    }

    /**
     * Move funds between same-currency balances.
     *
     * Guards both balances before HTTP.
     *
     * @param  int  $sourceBalanceId  The source balance ID.
     * @param  int  $targetBalanceId  The target balance ID.
     * @param  BrickMoney  $amount  The amount to move.
     * @param  string|null  $idempotencyKey  The idempotency key for safe retries.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceMovement The balance movement.
     *
     * @throws InvalidArgumentException
     * @throws BalanceNotActionable
     * @throws WiseApiError
     */
    public function move(int $sourceBalanceId, int $targetBalanceId, BrickMoney $amount, ?string $idempotencyKey = null, ?int $profileId = null): BalanceMovement
    {
        $source = $this->find($sourceBalanceId, $profileId);
        $target = $this->find($targetBalanceId, $profileId);

        $this->assertActionable($source);
        $this->assertActionable($target);

        $amountCurrency = $amount->getCurrency()->getCurrencyCode();
        if (strtoupper($source->currency) !== strtoupper($target->currency)
            || strtoupper($source->currency) !== $amountCurrency) {
            throw new InvalidArgumentException('Balance moves require source, target, and amount currencies to match.');
        }

        return $this->movement([
            'sourceBalanceId' => $sourceBalanceId,
            'targetBalanceId' => $targetBalanceId,
            'amount' => [
                'value' => Money::toWiseNumber($amount),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
            ],
        ], Idempotency::forKey($idempotencyKey, $this->namespace($profileId)), $profileId);
    }

    /**
     * Get a statement for a balance.
     *
     * Balance statements are SCA-protected outside the US, CA, AU, NZ, SG and MY. Delegates to StatementsApi.
     *
     * @param  int  $balanceId  The balance ID.
     * @param  string  $currency  The currency code.
     * @param  DateTimeInterface  $intervalStart  The interval start.
     * @param  DateTimeInterface  $intervalEnd  The interval end.
     * @param  string  $type  The statement type.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @param  string|null  $locale  The statement locale.
     * @return Statement The statement.
     *
     * @throws WiseApiError
     */
    public function statement(
        int $balanceId,
        string $currency,
        DateTimeInterface $intervalStart,
        DateTimeInterface $intervalEnd,
        string $type = 'COMPACT',
        ?int $profileId = null,
        ?string $locale = null,
    ): Statement {
        return $this->statements()->get($balanceId, $currency, $intervalStart, $intervalEnd, $type, $locale, $profileId);
    }

    /**
     * Get the statements API for the current account.
     *
     * @return StatementsApi The statements API instance.
     */
    public function statements(): StatementsApi
    {
        return new StatementsApi($this->client, $this->context);
    }

    /**
     * Execute a balance movement request.
     *
     * @param  array<string, mixed>  $body  The request body.
     * @param  string  $idempotencyUuid  The idempotency UUID.
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return BalanceMovement The balance movement.
     *
     * @throws WiseApiError
     */
    private function movement(array $body, string $idempotencyUuid, ?int $profileId): BalanceMovement
    {
        return BalanceMovement::fromArray($this->client->requestOperation(
            'balances.movement',
            ['profileId' => $this->profileId($profileId)],
            body: $body,
            headers: [self::IDEMPOTENCE_HEADER => $idempotencyUuid],
        ));
    }

    /**
     * Get the quotes API for the current account.
     *
     * @return QuotesApi The quotes API instance.
     */
    private function quotes(): QuotesApi
    {
        return new QuotesApi($this->client, $this->context);
    }

    /**
     * Assert that a balance can be operated on.
     *
     * @param  Balance  $balance  The balance to check.
     *
     * @throws BalanceNotActionable
     */
    private function assertActionable(Balance $balance): void
    {
        if (! $balance->isActionable()) {
            throw new BalanceNotActionable(
                "Balance {$balance->id} ({$balance->currency}, {$balance->investmentState}) is not actionable via the API; only NOT_INVESTED balances can be operated on.",
            );
        }
    }

    /**
     * Get the idempotency namespace for the profile.
     *
     * @param  int|null  $profileId  The profile ID, falling back to the account context.
     * @return string The idempotency namespace.
     *
     * @throws MissingProfile
     */
    private function namespace(?int $profileId): string
    {
        return "{$this->context->name}:{$this->profileId($profileId)}";
    }
}

<?php

namespace Owise\Testing;

/**
 * Sensible default Wise API responses for {@see WiseFake}.
 *
 * Every public operation resolves to a minimal-but-valid payload, so a test can exercise the real
 * adapters and DTOs without hand-authoring fixtures. Callers override these with
 * {@see WiseFake::respond()} and {@see WiseFake::fail()}.
 */
final class FakeResponses
{
    /**
     * Return the default response for an operation, or null when there is no sensible default.
     *
     * @return array{status: int, body: array<array-key, mixed>}|null
     */
    public static function for(string $operation): ?array
    {
        $body = match ($operation) {
            'profiles.list' => [self::profile()],
            'profiles.find' => self::profile(),
            'quotes.create', 'quotes.find', 'quotes.update' => self::quote(),
            'recipients.requirements.get', 'recipients.requirements.refresh' => self::accountRequirements(),
            'recipients.create', 'recipients.find' => self::recipient(),
            'recipients.list' => self::recipientList(),
            'transfers.requirements' => [],
            'transfers.create', 'transfers.find' => self::transfer(),
            'transfers.list' => [self::transfer()],
            'transfers.cancel' => self::transfer(),
            'transfers.fund' => self::funding(),
            'transfers.payments' => self::payments(),
            'balances.list' => [self::balance()],
            'balances.find', 'balances.create' => self::balance(),
            'balances.capacity' => self::balanceCapacity(),
            'balances.total-funds' => self::totalFunds(),
            'balances.movement' => self::balanceMovement(),
            'balances.statement' => self::statement(),
            'simulation.transfer-state' => self::transfer(),
            'simulation.balance-top-up' => self::simulationTopUp(),
            'webhooks.profile.create', 'webhooks.profile.find' => self::webhookSubscription(),
            'webhooks.profile.list' => self::webhookSubscriptionList(),
            'webhooks.application.create', 'webhooks.application.find' => self::webhookSubscription(),
            'webhooks.application.list' => self::webhookSubscriptionList(),
            'oauth.token' => self::oauthToken(),
            'recipients.delete', 'balances.close',
            'webhooks.profile.delete', 'webhooks.application.delete', 'webhooks.application.test' => [],
            default => null,
        };

        if ($body === null) {
            return null;
        }

        if (in_array($operation, [
            'recipients.delete',
            'balances.close',
            'webhooks.profile.delete',
            'webhooks.application.delete',
        ], true)) {
            return ['status' => 204, 'body' => []];
        }

        if ($operation === 'webhooks.application.test') {
            return ['status' => 200, 'body' => []];
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * A newly created transfer, ready for funding.
     *
     * @return array<array-key, mixed>
     */
    public static function transfer(?int $id = null, ?string $customerTransactionId = null): array
    {
        return [
            'id' => $id ?? 16521632,
            'user' => 4342275,
            'targetAccount' => 8692237,
            'sourceAccount' => null,
            'quote' => null,
            'quoteUuid' => '8fa9be20-ba43-4b15-abbb-9424e1481050',
            'status' => 'incoming_payment_waiting',
            'reference' => 'reference text',
            'rate' => 0.89,
            'created' => '2017-11-24 10:47:49',
            'business' => null,
            'transferRequest' => null,
            'details' => ['reference' => 'Testing'],
            'hasActiveIssues' => false,
            'sourceCurrency' => 'EUR',
            'sourceValue' => 0,
            'targetCurrency' => 'GBP',
            'targetValue' => 150,
            'customerTransactionId' => $customerTransactionId ?? '54a6bc09-cef9-49a8-9041-f1f0c654cd88',
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function profile(): array
    {
        return [
            'type' => 'PERSONAL',
            'id' => 101,
            'publicId' => 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
            'firstName' => 'Owise',
            'lastName' => 'User',
            'fullName' => 'Owise User',
            'email' => 'owise@example.com',
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function quote(): array
    {
        return [
            'id' => '11144c35-9fe8-4c32-b7fd-d05c2a7734bf',
            'sourceCurrency' => 'GBP',
            'targetCurrency' => 'EUR',
            'sourceAmount' => 100,
            'targetAmount' => 85.5,
            'payOut' => 'BANK_TRANSFER',
            'preferredPayIn' => 'BANK_TRANSFER',
            'rate' => 0.855,
            'rateType' => 'FIXED',
            'status' => 'PENDING',
            'createdTime' => '2019-04-05T13:18:58Z',
            'rateExpirationTime' => '2099-04-08T13:18:57Z',
            'expirationTime' => '2099-04-05T13:48:58Z',
            'paymentOptions' => [
                [
                    'disabled' => false,
                    'sourceAmount' => 100,
                    'targetAmount' => 85.5,
                    'sourceCurrency' => 'GBP',
                    'targetCurrency' => 'EUR',
                    'payIn' => 'BANK_TRANSFER',
                    'payOut' => 'BANK_TRANSFER',
                    'payInProduct' => 'CHEAP',
                    'feePercentage' => 0.0092,
                ],
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function recipient(): array
    {
        return [
            'id' => 40000000,
            'profileId' => 30000000,
            'name' => ['fullName' => 'John Doe'],
            'currency' => 'GBP',
            'country' => 'GB',
            'type' => 'SortCode',
            'legalEntityType' => 'PERSON',
            'active' => true,
            'details' => [
                'sortCode' => '040075',
                'accountNumber' => '37778842',
            ],
            'accountSummary' => '(04-00-75) 37778842',
            'longAccountSummary' => 'GBP account ending in 8842',
            'ownedByCustomer' => false,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function recipientList(): array
    {
        return [
            'content' => [self::recipient()],
            'seekPositionForNext' => 40000001,
            'seekPositionForCurrent' => 40000000,
            'size' => 20,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function accountRequirements(): array
    {
        return [
            [
                'type' => 'iban',
                'title' => 'Recipient account',
                'fields' => [
                    [
                        'name' => 'Account holder name',
                        'group' => [
                            [
                                'key' => 'accountHolderName',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function funding(): array
    {
        return [
            'type' => 'BALANCE',
            'status' => 'COMPLETED',
            'balanceTransactionId' => 987654321,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function payments(): array
    {
        return [
            [
                'type' => 'BALANCE',
                'status' => 'COMPLETED',
                'balanceTransactionId' => 987654321,
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function balance(): array
    {
        return [
            'id' => 200001,
            'currency' => 'EUR',
            'type' => 'STANDARD',
            'investmentState' => 'NOT_INVESTED',
            'amount' => ['value' => 310.86, 'currency' => 'EUR'],
            'reservedAmount' => ['value' => 0, 'currency' => 'EUR'],
            'cashAmount' => ['value' => 310.86, 'currency' => 'EUR'],
            'totalWorth' => ['value' => 310.86, 'currency' => 'EUR'],
            'visible' => true,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function balanceCapacity(): array
    {
        return [
            'hasLimit' => true,
            'depositLimit' => ['amount' => 2000, 'currency' => 'SGD'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function totalFunds(): array
    {
        return [
            'totalWorth' => ['value' => 2000, 'currency' => 'EUR'],
            'totalAvailable' => ['value' => 2500, 'currency' => 'EUR'],
            'totalCash' => ['value' => 2000, 'currency' => 'EUR'],
            'overdraft' => [
                'limit' => ['value' => 500, 'currency' => 'EUR'],
                'used' => ['value' => 100, 'currency' => 'EUR'],
                'available' => ['value' => 400, 'currency' => 'EUR'],
                'availableByCurrency' => [['value' => 400, 'currency' => 'EUR']],
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function balanceMovement(): array
    {
        return [
            'id' => 30000001,
            'type' => 'CONVERSION',
            'state' => 'COMPLETED',
            'sourceAmount' => ['value' => 113.48, 'currency' => 'EUR'],
            'targetAmount' => ['value' => 100, 'currency' => 'GBP'],
            'rate' => 0.88558,
            'feeAmounts' => [['value' => 0.56, 'currency' => 'EUR']],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function statement(): array
    {
        return [
            'accountHolder' => [
                'type' => 'PERSONAL',
                'firstName' => 'Oliver',
                'lastName' => 'Wilson',
            ],
            'transactions' => [],
            'endOfStatementBalance' => ['value' => 9.94, 'currency' => 'EUR'],
            'query' => [
                'intervalStart' => '2018-03-01T00:00:00Z',
                'intervalEnd' => '2018-04-30T23:59:59.999Z',
                'currency' => 'EUR',
                'accountId' => 64,
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function simulationTopUp(): array
    {
        return [
            'transactionId' => 5,
            'state' => 'COMPLETED',
            'balancesAfter' => [
                ['id' => 5, 'value' => 100, 'currency' => 'EUR'],
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function webhookSubscription(): array
    {
        return [
            'id' => '72195556-e5cb-495e-a010-b37a4f2a3043',
            'name' => 'Webhook Subscription #1',
            'delivery' => [
                'version' => '4.0.0',
                'url' => 'https://your.webhook.url/12345',
            ],
            'trigger_on' => 'transfers#state-change',
            'scope' => ['domain' => 'profile', 'id' => '101'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function webhookSubscriptionList(): array
    {
        return [self::webhookSubscription()];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function oauthToken(): array
    {
        return [
            'access_token' => '01234567-89ab-cdef-0123-456789abcdef',
            'token_type' => 'bearer',
            'refresh_token' => '01234567-89ab-cdef-0123-456789abcdef',
            'expires_in' => 43199,
            'expires_at' => '2099-04-11T03:43:28.148Z',
            'refresh_token_expires_in' => 628639555,
            'refresh_token_expires_at' => '2099-03-12T13:49:23.552Z',
            'scope' => 'transfers',
        ];
    }
}

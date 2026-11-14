<?php

use Illuminate\Support\Facades\Event;
use Owise\Events\BalanceCredited;
use Owise\Events\BalanceUpdated;
use Owise\Webhooks\Handlers\BalanceCredit;
use Owise\Webhooks\Handlers\BalanceUpdate;
use Owise\Webhooks\Payload;

it('maps balance update webhooks to Money amounts', function () {
    Event::fake([BalanceUpdated::class]);

    (new BalanceUpdate)->handle(Payload::fromArray([
        'event_type' => 'balances#update',
        'schema_version' => '4.0.0',
        'subscription_id' => 'sub-1',
        'sent_at' => '2023-03-08T14:55:39.456Z',
        'data' => [
            'resource' => ['type' => 'balance-account', 'id' => 2, 'profile_id' => 2],
            'amount' => 70,
            'currency' => 'GBP',
            'post_transaction_balance_amount' => 88.93,
            'step_id' => 1234567,
            'occurred_at' => '2023-03-08T14:55:38.123Z',
        ],
    ]));

    Event::assertDispatched(BalanceUpdated::class, fn (BalanceUpdated $event): bool => $event->balanceId === '2'
        && $event->profileId === '2'
        && $event->stepId === '1234567'
        && (string) $event->amount?->getAmount() === '70.00'
        && $event->amount?->getCurrency()->getCurrencyCode() === 'GBP'
        && (string) $event->postTransactionBalance?->getAmount() === '88.93'
        && $event->occurredAt !== null);
});

it('maps legacy balance credit webhooks to Money amounts', function () {
    Event::fake([BalanceCredited::class]);

    (new BalanceCredit)->handle(Payload::fromArray([
        'event_type' => 'balances#credit',
        'schema_version' => '4.0.0',
        'subscription_id' => 'sub-2',
        'sent_at' => '2020-01-01T12:34:56.123Z',
        'data' => [
            'resource' => ['type' => 'balance-account', 'id' => 111, 'profile_id' => 222],
            'amount' => 1.23,
            'currency' => 'EUR',
            'post_transaction_balance_amount' => 2.34,
            'occurred_at' => '2020-01-01T12:34:56.789Z',
        ],
    ]));

    Event::assertDispatched(BalanceCredited::class, fn (BalanceCredited $event): bool => $event->balanceId === '111'
        && (string) $event->amount?->getAmount() === '1.23'
        && (string) $event->postTransactionBalance?->getAmount() === '2.34');
});

<?php

namespace Owise\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Build schema 4.0.0 Wise webhook payloads for tests.
 *
 * Pair with {@see InteractsWithWiseWebhooks::postWiseWebhook()} to exercise the real signature
 * verification middleware end to end.
 */
final class WebhookFactory
{
    /**
     * Build a `transfers#state-change` payload.
     *
     * @param  int|string  $transferId  The Wise transfer id.
     * @param  string|DateTimeInterface|null  $occurredAt  The `occurred_at` timestamp (defaults to now).
     * @return array<string, mixed>
     */
    public static function transferStateChanged(
        int|string $transferId,
        string $from,
        string $to,
        string|DateTimeInterface|null $occurredAt = null,
        ?int $profileId = null,
    ): array {
        $data = [
            'resource' => ['type' => 'transfer', 'id' => (string) $transferId],
            'previous_state' => $from,
            'current_state' => $to,
            'occurred_at' => self::timestamp($occurredAt),
        ];

        if ($profileId !== null) {
            $data['profile_id'] = $profileId;
        }

        return self::payload('transfers#state-change', $data);
    }

    /**
     * Build a legacy `balances#credit` payload.
     *
     * @return array<string, mixed>
     */
    public static function balanceCredited(
        int|string $balanceId,
        string $currency,
        string|int|float $amount,
        string|int|float|null $postTransactionBalance = null,
        string|DateTimeInterface|null $occurredAt = null,
        ?int $profileId = null,
    ): array {
        return self::balancePayload('balances#credit', $balanceId, $currency, $amount, $postTransactionBalance, $occurredAt, $profileId);
    }

    /**
     * Build a `balances#update` payload.
     *
     * @return array<string, mixed>
     */
    public static function balanceUpdated(
        int|string $balanceId,
        string $currency,
        string|int|float $amount,
        string|int|float|null $postTransactionBalance = null,
        string|DateTimeInterface|null $occurredAt = null,
        ?int $profileId = null,
        ?string $stepId = null,
    ): array {
        $data = self::balanceData($balanceId, $currency, $amount, $postTransactionBalance, $occurredAt, $profileId);

        if ($stepId !== null) {
            $data['step_id'] = $stepId;
        }

        return self::payload('balances#update', $data);
    }

    /**
     * Build a payload for an arbitrary event type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function payload(string $eventType, array $data): array
    {
        return [
            'data' => $data,
            'subscription_id' => 'sub-123',
            'event_type' => $eventType,
            'schema_version' => '4.0.0',
            'sent_at' => self::timestamp(null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function balancePayload(
        string $eventType,
        int|string $balanceId,
        string $currency,
        string|int|float $amount,
        string|int|float|null $postTransactionBalance,
        string|DateTimeInterface|null $occurredAt,
        ?int $profileId,
    ): array {
        return self::payload($eventType, self::balanceData($balanceId, $currency, $amount, $postTransactionBalance, $occurredAt, $profileId));
    }

    /**
     * @return array<string, mixed>
     */
    private static function balanceData(
        int|string $balanceId,
        string $currency,
        string|int|float $amount,
        string|int|float|null $postTransactionBalance,
        string|DateTimeInterface|null $occurredAt,
        ?int $profileId,
    ): array {
        $data = [
            'resource' => ['type' => 'balance', 'id' => (string) $balanceId],
            'currency' => $currency,
            'amount' => $amount,
            'occurred_at' => self::timestamp($occurredAt),
        ];

        if ($postTransactionBalance !== null) {
            $data['post_transaction_balance_amount'] = $postTransactionBalance;
        }

        if ($profileId !== null) {
            $data['profile_id'] = $profileId;
        }

        return $data;
    }

    private static function timestamp(string|DateTimeInterface|null $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $timestamp = $value === null
            ? DateTimeImmutable::createFromInterface(now())
            : DateTimeImmutable::createFromInterface($value);

        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}

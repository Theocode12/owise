<?php

namespace Owise\Webhooks;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalized Wise webhook payload.
 */
final readonly class Payload
{
    /**
     * Create a new payload instance.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public array $raw,
        public array $data,
        public string $eventType,
        public string $schemaVersion,
        public ?string $deliveryId,
        public ?string $subscriptionId,
        public ?CarbonImmutable $sentAt,
        public bool $isTest,
        public ?CarbonImmutable $occurredAt,
        public ?string $stepId,
        public ?string $resourceType,
        public ?string $resourceId,
        public ?string $profileId,
    ) {}

    /**
     * Create a payload from a raw webhook body.
     *
     * @param  array<string, mixed>  $raw
     * @return static
     */
    public static function fromArray(array $raw): self
    {
        return self::fromRequest($raw, null, false);
    }

    /**
     * Create a payload from an incoming webhook request.
     *
     * @param  array<string, mixed>  $body
     * @return static
     */
    public static function fromRequest(array $body, ?string $deliveryId, bool $isTest): self
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $resource = is_array($data['resource'] ?? null) ? $data['resource'] : [];
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : [];

        return new self(
            raw: $body,
            data: $data,
            eventType: is_string($body['event_type'] ?? null) ? $body['event_type'] : '',
            schemaVersion: is_string($body['schema_version'] ?? null) ? $body['schema_version'] : '',
            deliveryId: $deliveryId,
            subscriptionId: self::string($body['subscription_id'] ?? null),
            sentAt: self::date($body['sent_at'] ?? null),
            isTest: $isTest,
            occurredAt: self::date($data['occurred_at'] ?? $resource['occurred_at'] ?? $data['modification_time'] ?? $resource['updatedAt'] ?? null),
            stepId: self::string($data['step_id'] ?? null),
            resourceType: self::string($resource['type'] ?? $data['resource_type'] ?? null),
            resourceId: self::string($resource['id'] ?? $data['resource_id'] ?? $data['transfer_id'] ?? null),
            profileId: self::string($data['profile_id'] ?? $profile['id'] ?? $resource['profile_id'] ?? null),
        );
    }

    /**
     * Get the first present string value for the given keys.
     *
     * @param  array<string, mixed>  $data
     */
    public static function value(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Normalize the given value to a string.
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * Parse the given value into an immutable date.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
